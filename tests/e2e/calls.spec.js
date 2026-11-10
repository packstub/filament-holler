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

    // A submit that lands before Livewire has bound the fields loses them: try again. Filament allows
    // five sign-ins a minute from one address, and the suite signs in three people per test: wait it out.
    for (let attempt = 1; ; attempt++) {
        await page.locator('input[type=email]').fill(email)
        await page.locator('input[type=password]').fill('password')
        await page.locator('input[type=password]').press('Enter')
        const signedIn = await page.waitForURL('**/admin', { timeout: 10_000 }).then(() => true, () => false)
        if (signedIn) break
        if (attempt === 4) throw new Error(`Could not sign in as ${email}`)
        const throttled = (await page.locator('body').innerText()).match(/try again in (\d+) seconds/i)
        if (throttled) await page.waitForTimeout((Number(throttled[1]) + 1) * 1000)
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
        ana.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click(),
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
    await anaWindow.getByRole('button', { name: 'Mute', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=muted]')).toBeVisible()
    // Her own window says so too: the pressed Unmute button, her picture "Your mic is off".
    await expect(anaWindow.getByRole('button', { name: 'Unmute', exact: true })).toHaveAttribute('aria-pressed', 'true')
    await expect(anaWindow.locator('[data-self=muted]')).toBeVisible()
    await anaWindow.getByRole('button', { name: 'Share screen', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=sharing]')).toBeVisible()
    // Her screen takes Ion's stage whole; with nobody else on the call there is no strip of cameras.
    await expect(ionWindow.locator('.fi-calls-stage')).toHaveAttribute('data-spotlight', /.+/)
    await expect(ionWindow.locator('.fi-calls-stage')).toHaveAttribute('data-strip', 'none')
    await expect(ionWindow.locator('.fi-calls-tile[data-role=spotlight] video')).toHaveCSS('object-fit', 'contain')
    await anaWindow.getByRole('button', { name: 'Stop sharing', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=sharing]')).toBeHidden()
    await expect(ionWindow.locator('.fi-calls-stage')).not.toHaveAttribute('data-spotlight')
    await anaWindow.getByRole('button', { name: 'Unmute', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=muted]')).toBeHidden()
    await expect(anaWindow.locator('[data-self=muted]')).toBeHidden()
    await expect(anaWindow.getByRole('button', { name: 'Mute', exact: true })).toHaveAttribute('aria-pressed', 'false')

    // Ana turns her camera off: Ion sees her avatar instead of a black frame.
    await anaWindow.getByRole('button', { name: 'Turn camera off', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=camera-off]')).toBeVisible()
    await expect(ionWindow.locator('.fi-calls-placeholder')).toBeVisible()
    // Her own corner keeps its frame with her avatar; muted now, "Your mic is off" sits inside that frame.
    await expect(anaWindow.locator('[data-self=camera-off]')).toBeVisible()
    await anaWindow.getByRole('button', { name: 'Mute', exact: true }).click()
    await expect(anaWindow.locator('[data-self=muted]')).toBeVisible()
    const selfTile = await anaWindow.locator('[data-self-tile]').boundingBox()
    const selfBadge = await anaWindow.locator('[data-self=muted]').boundingBox()
    expect(selfBadge.x).toBeGreaterThanOrEqual(selfTile.x)
    expect(selfBadge.y + selfBadge.height).toBeLessThanOrEqual(selfTile.y + selfTile.height + 1)
    await expect(ionWindow.locator('[data-peer=muted]')).toBeVisible()
    await anaWindow.getByRole('button', { name: 'Unmute', exact: true }).click()
    await expect(anaWindow.locator('[data-self=muted]')).toBeHidden()

    // Ion hangs up: both windows close, both main tabs are idle and say so.
    await Promise.all([
        ionWindow.waitForEvent('close'),
        anaWindow.waitForEvent('close'),
        ionWindow.locator('.fi-calls-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
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
        ana.locator('.fi-calls-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
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
    await ana.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click()
    await expect(ana.getByRole('status').filter({ hasText: 'Pop-ups are blocked' })).toBeVisible()
    await expectActive(ion)
    await expectActive(ana)
    expect(ana.context().pages()).toHaveLength(1)

    await ana.locator('.fi-calls-window').getByRole('button', { name: 'Hang up', exact: true }).click()
    await expect.poll(() => state(ion)).toBe('idle')
    await expect(ion.getByRole('status').filter({ hasText: 'Call ended' })).toBeVisible()
})

/** The tile of a participant on a page, by their name. */
const tile = (page, name) => page.locator('.fi-calls-tile').filter({ has: page.locator('.fi-calls-tile-name', { hasText: name }) })

/** Names and media of every tile on a page, e.g. [['Ana Popescu', ['audio:live', 'video:live']]]. */
const peers = (page) => page.evaluate(() => [...document.querySelectorAll('.fi-calls-tile')].map((tile) => [
    tile.querySelector('.fi-calls-tile-name span')?.textContent.trim(),
    tile.querySelector('video')?.srcObject ? tile.querySelector('video').srcObject.getTracks().map((track) => `${track.kind}:${track.readyState}`).sort() : [],
]).sort((a, b) => a[0].localeCompare(b[0])))

const expectPeers = (page, names) => expect.poll(() => peers(page), { timeout: 30_000 }).toEqual(names.map((name) => [name, ['audio:live', 'video:live']]))

test('three people on one call: the third is added from the Team page, everyone sees everyone, and the call goes on when one leaves', async ({ browser }) => {
    const mihai = await signIn(await browser.newContext(), 'mihai@example.com')

    try {
        const ionWindow = await ring(ion)
        const anaWindow = await answer(ana)
        await expectActive(ionWindow)
        await expectActive(anaWindow)

        // Ion adds Mihai from the Team page while his pop-out runs the call.
        await ion.getByRole('row', { name: /Mihai Rusu/ }).getByRole('button', { name: 'Call' }).click()
        await expect(ion.getByRole('status').filter({ hasText: 'Ringing Mihai Rusu' })).toBeVisible()
        await expect(mihai.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
        await expect(mihai.getByRole('alertdialog')).toContainText('+1')
        await expect(ionWindow.locator('.fi-calls-tile-name', { hasText: 'Mihai Rusu' })).toContainText('Calling')

        const [mihaiWindow] = await Promise.all([
            mihai.context().waitForEvent('page'),
            mihai.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click(),
        ])

        // Everyone has a tile for each of the other two, with live audio and video.
        await expectPeers(ionWindow, ['Ana Popescu', 'Mihai Rusu'])
        await expectPeers(anaWindow, ['Ion Caliman', 'Mihai Rusu'])
        await expectPeers(mihaiWindow, ['Ana Popescu', 'Ion Caliman'])
        await expect(ionWindow.locator('.fi-calls-grid')).toHaveAttribute('data-count', '2')
        await expect.poll(() => state(mihaiWindow)).toBe('active')
        await expect(ionWindow.locator('.fi-calls-header .fi-calls-name')).toHaveText('Ana Popescu, Mihai Rusu')

        // Mihai mutes: his tile says so in the other two windows, and nobody else's does.
        await mihaiWindow.getByRole('button', { name: 'Mute', exact: true }).click()
        await expect(tile(ionWindow, 'Mihai Rusu').locator('[data-peer=muted]')).toBeVisible()
        await expect(tile(ionWindow, 'Ana Popescu').locator('[data-peer=muted]')).toBeHidden()
        await expect(tile(anaWindow, 'Mihai Rusu').locator('[data-peer=muted]')).toBeVisible()

        // Mihai shares his screen: in the other two windows (460 wide, taller than wide) his tile takes
        // the stage and the other camera moves into a strip on top; the look goes back once he stops.
        await mihaiWindow.getByRole('button', { name: 'Share screen', exact: true }).click()
        await expect(tile(ionWindow, 'Mihai Rusu')).toHaveAttribute('data-role', 'spotlight')
        await expect(tile(ionWindow, 'Ana Popescu')).toHaveAttribute('data-role', 'strip')
        await expect(ionWindow.locator('.fi-calls-stage')).toHaveAttribute('data-strip', 'top')
        await expect(tile(anaWindow, 'Mihai Rusu')).toHaveAttribute('data-role', 'spotlight')
        const [spotlight, strip] = await Promise.all([tile(ionWindow, 'Mihai Rusu').boundingBox(), tile(ionWindow, 'Ana Popescu').boundingBox()])
        expect(spotlight.height).toBeGreaterThan(strip.height * 2)
        expect(strip.y).toBeLessThan(spotlight.y)
        // A wide window puts the strip on the right instead.
        await ionWindow.setViewportSize({ width: 900, height: 500 })
        await expect(ionWindow.locator('.fi-calls-stage')).toHaveAttribute('data-strip', 'right')
        await ionWindow.setViewportSize({ width: 460, height: 620 })
        await expect(ionWindow.locator('.fi-calls-stage')).toHaveAttribute('data-strip', 'top')
        await mihaiWindow.getByRole('button', { name: 'Stop sharing', exact: true }).click()
        await expect(ionWindow.locator('.fi-calls-stage')).not.toHaveAttribute('data-spotlight')
        await expect(tile(ionWindow, 'Mihai Rusu')).not.toHaveAttribute('data-role')

        // Ana reloads her window: both others see her back within seconds, Mihai included.
        await anaWindow.reload()
        await expectPeers(anaWindow, ['Ion Caliman', 'Mihai Rusu'])
        await expectPeers(ionWindow, ['Ana Popescu', 'Mihai Rusu'])
        await expectPeers(mihaiWindow, ['Ana Popescu', 'Ion Caliman'])

        // Mihai hangs up: his window closes, the other two go on.
        await Promise.all([
            mihaiWindow.waitForEvent('close'),
            mihaiWindow.locator('.fi-calls-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
        ])
        await expect(ionWindow.getByRole('status').filter({ hasText: 'Mihai Rusu left the call' })).toBeVisible()
        await expectPeers(ionWindow, ['Ana Popescu'])
        await expectPeers(anaWindow, ['Ion Caliman'])
        await expect.poll(() => state(ionWindow)).toBe('active')
        await expect.poll(() => state(anaWindow)).toBe('active')
        await expect.poll(() => state(mihai)).toBe('idle')
    } finally {
        await mihai.evaluate((selector) => { const widget = document.querySelector(selector); if (widget) Alpine.$data(widget).hangUp() }, WIDGET).catch(() => {})
        await mihai.context().close()
    }
})

test('a pop-out link alone neither rings anyone nor answers a call', async () => {
    // Ana's id, from the Call button on Ion's Team page.
    const handler = await ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).evaluate((button) => button.outerHTML)
    const anaId = handler.match(/user: '([^']+)'/)[1]

    // Ion opens a window link that names Ana: it only polls, nobody rings.
    const link = await ion.context().newPage()
    await link.goto(`/admin/filament-calls/window?callee=${anaId}`)
    await expect(link.locator(WIDGET)).toBeAttached()
    await link.waitForTimeout(3000)
    expect(await state(link)).toBe('idle')
    await expect(ana.getByRole('alertdialog')).toBeHidden()
    await link.close()

    // Ion rings Ana for real; a link to that call on Ana's side shows the call with Accept, it does not answer.
    const ionWindow = await ring(ion)
    await expect.poll(() => state(ionWindow)).toBe('calling')
    await expect.poll(() => new URL(ionWindow.url()).searchParams.get('call')).toMatch(/^\d+$/)
    const callId = new URL(ionWindow.url()).searchParams.get('call')

    const anaLink = await ana.context().newPage()
    await anaLink.goto(`/admin/filament-calls/window?call=${callId}`)
    await expect(anaLink.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    await anaLink.waitForTimeout(2000)
    expect(await state(anaLink)).toBe('ringing')
    expect(await state(ionWindow)).toBe('calling')

    // A click on Accept is what answers.
    await anaLink.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click()
    await expectActive(anaLink)
    await expectActive(ionWindow)
})

test('a name with markup in a notification stays text', async () => {
    const text = 'Dana <a href="https://example.com">Popa</a> & <b>Co</b> left the call'
    await ion.evaluate(([selector, text]) => Alpine.$data(document.querySelector(selector)).show(text, 'info'), [WIDGET, text])

    const notification = ion.locator('.fi-no-notification').filter({ hasText: 'Dana' })
    await expect(notification).toContainText(text)
    await expect(notification.locator('a, b')).toHaveCount(0)
})

test('a call for the keyboard and the screen reader: announced, titled, and the focus stays on a toggle', async () => {
    const ionWindow = await ring(ion)
    await expect(ana.locator('[aria-live=assertive]')).toHaveText('Ion Caliman is calling you', { timeout: 30_000 })

    const anaWindow = await answer(ana)
    await expectActive(anaWindow)
    await expect(anaWindow).toHaveTitle(/^Ion Caliman · Call/)
    await expect(ana.getByRole('region', { name: 'Ion Caliman' })).toBeVisible()

    // Mute and unmute from the keyboard: the button that takes the clicked one's place has the focus.
    await anaWindow.getByRole('button', { name: 'Mute', exact: true }).focus()
    await anaWindow.keyboard.press('Enter')
    await expect(anaWindow.getByRole('button', { name: 'Unmute', exact: true })).toBeFocused()
    await anaWindow.keyboard.press('Enter')
    await expect(anaWindow.getByRole('button', { name: 'Mute', exact: true })).toBeFocused()
    await anaWindow.getByRole('button', { name: 'Turn camera off', exact: true }).focus()
    await anaWindow.keyboard.press('Enter')
    await expect(anaWindow.getByRole('button', { name: 'Turn camera on', exact: true })).toBeFocused()

    await Promise.all([
        ionWindow.waitForEvent('close'),
        anaWindow.locator('.fi-calls-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
    ])
})
