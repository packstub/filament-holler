/**
 * Two people on the workbench panel call each other for real: two browser contexts
 * (Ion and Ana), the pop-out windows, WebRTC over loopback with a fake camera and
 * microphone, the reconnect after a reload, mute and screen share seen by the other
 * side, the main tab taking the call back when its pop-out closes, and the page
 * itself when the browser blocks pop-ups.
 */

import { expect, test } from '@playwright/test'

const WIDGET = '[x-data*="packstubCalls"]'

async function signIn(context, email) {
    const page = await context.newPage()
    await page.goto('/admin/login', { waitUntil: 'networkidle' })

    // A submit that lands before Livewire has bound the fields loses them: try again.
    for (let attempt = 1; ; attempt++) {
        await page.locator('input[type=email]').fill(email)
        await page.locator('input[type=password]').fill('password')
        await page.locator('input[type=password]').press('Enter')
        const signedIn = await page.waitForURL('**/admin', { timeout: 10_000 }).then(() => true, () => false)
        if (signedIn) break
        if (attempt === 3) throw new Error(`Could not sign in as ${email}`)
    }

    await page.goto('/admin/users')
    await expect(page.locator(WIDGET)).toBeAttached()

    return page
}

/** The Alpine component's state on a page: idle | calling | ringing | connecting | active | reconnecting | elsewhere. */
const state = (page) => page.evaluate((selector) => Alpine.$data(document.querySelector(selector)).state, WIDGET)

/** Kinds and states of the tracks on the remote <video>, e.g. ['audio:live', 'video:live']. */
const remoteTracks = (page) => page.evaluate((selector) => {
    const video = document.querySelector(`${selector} video.fi-calls-remote`)

    return video?.srcObject ? video.srcObject.getTracks().map((track) => `${track.kind}:${track.readyState}`).sort() : []
}, WIDGET)

const expectActive = async (page) => {
    await expect.poll(() => state(page), { timeout: 30_000 }).toBe('active')
    await expect.poll(() => remoteTracks(page)).toEqual(['audio:live', 'video:live'])
}

/** Ion rings Ana from her row on the Team page; the pop-out that opens is returned. */
async function ring(ion) {
    const [window] = await Promise.all([
        ion.context().waitForEvent('page'),
        ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).click(),
    ])
    await expect(window).toHaveURL(/filament-calls\/window/)

    return window
}

/** Ana accepts from the toast on her page; her pop-out is returned. */
async function answer(ana) {
    await expect(ana.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    const [window] = await Promise.all([
        ana.context().waitForEvent('page'),
        ana.getByRole('alertdialog').getByTitle('Accept').click(),
    ])

    return window
}

let ion
let ana

test.beforeEach(async ({ browser }) => {
    ion = await signIn(await browser.newContext(), 'ion@example.com')
    ana = await signIn(await browser.newContext(), 'ana@example.com')
})

test.afterEach(async () => {
    // Whatever happened, nobody stays on a call for the next test.
    for (const page of [ion, ana]) {
        if (page.isClosed()) continue
        await page.evaluate((selector) => {
            const widget = document.querySelector(selector)
            if (widget) Alpine.$data(widget).hangUp()
        }, WIDGET).catch(() => {})
    }
    await ion.context().close()
    await ana.context().close()
})

test('a call between two people in their pop-outs: connected, reconnected after a reload, mute and screen share seen by the other side, hung up', async () => {
    const ionWindow = await ring(ion)
    await expect.poll(() => state(ionWindow)).toBe('calling')
    await expect(ion.locator('.fi-calls-chip')).toBeVisible()

    const anaWindow = await answer(ana)
    await expectActive(ionWindow)
    await expectActive(anaWindow)
    // The main tabs show the chip with the timer, the call lives in the windows.
    await expect.poll(() => state(ion)).toBe('elsewhere')
    await expect.poll(() => state(ana)).toBe('elsewhere')
    await expect(ana.locator('.fi-calls-chip')).toContainText(/\d:\d\d/)

    // Ion reloads his window mid-call: both sides are back within seconds.
    await ionWindow.reload()
    await expectActive(ionWindow)
    await expectActive(anaWindow)

    // Ana mutes and shares her screen: Ion's window says so.
    await anaWindow.getByTitle('Mute').click()
    await expect(ionWindow.locator('[data-peer=muted]')).toBeVisible()
    await anaWindow.getByTitle('Share screen').click()
    await expect(ionWindow.locator('[data-peer=sharing]')).toBeVisible()
    await anaWindow.getByTitle('Stop sharing').click()
    await expect(ionWindow.locator('[data-peer=sharing]')).toBeHidden()
    await anaWindow.getByTitle('Unmute').click()
    await expect(ionWindow.locator('[data-peer=muted]')).toBeHidden()

    // Ana turns her camera off: Ion sees her avatar instead of a black frame.
    await anaWindow.getByTitle('Turn camera off').click()
    await expect(ionWindow.locator('[data-peer=camera-off]')).toBeVisible()
    await expect(ionWindow.locator('.fi-calls-placeholder')).toBeVisible()

    // Ion hangs up: both windows close, both main tabs are idle and say so.
    await Promise.all([
        ionWindow.waitForEvent('close'),
        anaWindow.waitForEvent('close'),
        ionWindow.locator('.fi-calls-window').getByTitle('Hang up').click(),
    ])
    await expect(ana.getByRole('status').filter({ hasText: 'Call ended' })).toBeVisible()
    await expect.poll(() => state(ion)).toBe('idle')
    await expect.poll(() => state(ana)).toBe('idle')
})

test('the main tab continues the call itself when its pop-out closes, and the other side reconnects', async () => {
    const ionWindow = await ring(ion)
    const anaWindow = await answer(ana)
    await expectActive(ionWindow)
    await expectActive(anaWindow)

    // Ana closes her pop-out: her main tab takes the call back and tells her.
    await anaWindow.close()
    await expect(ana.getByRole('status').filter({ hasText: 'continues here' })).toBeVisible({ timeout: 30_000 })
    await expectActive(ana)
    await expect(ana.locator('.fi-calls-window')).toBeVisible()
    await expectActive(ionWindow)

    // Ana hangs up from the page.
    await Promise.all([
        ionWindow.waitForEvent('close'),
        ana.locator('.fi-calls-window').getByTitle('Hang up').click(),
    ])
    await expect.poll(() => state(ana)).toBe('idle')
})

test('the call stays on the page when the browser blocks the pop-out', async () => {
    for (const page of [ion, ana]) {
        await page.context().addInitScript(() => { window.open = () => null })
        await page.reload()
    }

    await ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).click()
    await expect(ion.getByRole('status').filter({ hasText: 'Pop-ups are blocked' })).toBeVisible()
    await expect.poll(() => state(ion)).toBe('calling')
    expect(ion.context().pages()).toHaveLength(1)

    await expect(ana.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    await ana.getByRole('alertdialog').getByTitle('Accept').click()
    await expect(ana.getByRole('status').filter({ hasText: 'Pop-ups are blocked' })).toBeVisible()
    await expectActive(ion)
    await expectActive(ana)
    expect(ana.context().pages()).toHaveLength(1)

    await ana.locator('.fi-calls-window').getByTitle('Hang up').click()
    await expect.poll(() => state(ion)).toBe('idle')
    await expect(ion.getByRole('status').filter({ hasText: 'Call ended' })).toBeVisible()
})
