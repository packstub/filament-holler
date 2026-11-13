/**
 * Two people on the workbench panel call each other for real: two browser contexts
 * (Ion and Ana), the pop-out windows, WebRTC over loopback with a fake camera and
 * microphone, the reconnect after a reload, mute and screen share seen by the other
 * side, the main tab taking the call back when its pop-out closes, and the page
 * itself when the browser blocks pop-ups.
 */

import { expect, test } from '@playwright/test'
import { WIDGET, answer, expectActive, leaveCalls, remoteTracks, ring, signIn, state } from './support.js'

let ion
let ana

test.beforeEach(async ({ browser }) => {
    ion = await signIn(await browser.newContext(), 'ion@example.com')
    ana = await signIn(await browser.newContext(), 'ana@example.com')
})

test.afterEach(async () => {
    // Whatever happened, nobody stays on a call (or has one on hold) for the next test.
    for (const page of [ion, ana]) {
        if (page.isClosed()) continue
        await leaveCalls(page)
    }
    await ion.context().close()
    await ana.context().close()
})

test('a call between two people in their pop-outs: connected, reconnected after a reload, mute and screen share seen by the other side, hung up', async () => {
    const ionWindow = await ring(ion)
    await expect.poll(() => state(ionWindow)).toBe('calling')
    await expect(ion.locator('.fi-holler-chip')).toBeVisible()

    const anaWindow = await answer(ana)
    await expectActive(ionWindow)
    await expectActive(anaWindow)
    // The main tabs show the chip with the timer, the call lives in the windows.
    await expect.poll(() => state(ion)).toBe('elsewhere')
    await expect.poll(() => state(ana)).toBe('elsewhere')
    await expect(ana.locator('.fi-holler-chip')).toContainText(/\d:\d\d/)

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
    await expect(ionWindow.locator('.fi-holler-stage')).toHaveAttribute('data-spotlight', /.+/)
    await expect(ionWindow.locator('.fi-holler-stage')).toHaveAttribute('data-strip', 'none')
    await expect(ionWindow.locator('.fi-holler-tile[data-role=spotlight] video')).toHaveCSS('object-fit', 'contain')
    await anaWindow.getByRole('button', { name: 'Stop sharing', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=sharing]')).toBeHidden()
    await expect(ionWindow.locator('.fi-holler-stage')).not.toHaveAttribute('data-spotlight')
    await anaWindow.getByRole('button', { name: 'Unmute', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=muted]')).toBeHidden()
    await expect(anaWindow.locator('[data-self=muted]')).toBeHidden()
    await expect(anaWindow.getByRole('button', { name: 'Mute', exact: true })).toHaveAttribute('aria-pressed', 'false')

    // Ana turns her camera off: Ion sees her avatar instead of a black frame.
    await anaWindow.getByRole('button', { name: 'Turn camera off', exact: true }).click()
    await expect(ionWindow.locator('[data-peer=camera-off]')).toBeVisible()
    await expect(ionWindow.locator('.fi-holler-placeholder')).toBeVisible()
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
        ionWindow.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
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
    await expect(ana.locator('.fi-holler-window')).toBeVisible()
    await expectActive(ionWindow)

    // Ana hangs up from the page.
    await Promise.all([
        ionWindow.waitForEvent('close'),
        ana.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
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

    await ana.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click()
    await expect.poll(() => state(ion)).toBe('idle')
    await expect(ion.getByRole('status').filter({ hasText: 'Call ended' })).toBeVisible()
})

/** The tile of a participant on a page, by their name. */
const tile = (page, name) => page.locator('.fi-holler-tile').filter({ has: page.locator('.fi-holler-tile-name', { hasText: name }) })

/** Names and media of every tile on a page, e.g. [['Ana Popescu', ['audio:live', 'video:live']]]. */
const peers = (page) => page.evaluate(() => [...document.querySelectorAll('.fi-holler-tile')].map((tile) => [
    tile.querySelector('.fi-holler-tile-name span')?.textContent.trim(),
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

        // Ion adds Mihai from the Team page while his pop-out runs the call: the widget asks what to do with the call.
        await ion.getByRole('row', { name: /Mihai Rusu/ }).getByRole('button', { name: 'Call' }).click()
        await expect(ion.locator('[data-holler-prompt]')).toContainText('Mihai Rusu')
        await ion.locator('[data-holler-prompt]').getByRole('button', { name: 'Add to this call' }).click()
        await expect(ion.locator('[data-holler-prompt]')).toBeHidden()
        await expect(ion.getByRole('status').filter({ hasText: 'Ringing Mihai Rusu' })).toBeVisible()
        await expect(mihai.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
        await expect(mihai.getByRole('alertdialog')).toContainText('+1')
        // Rung, not on the stage until he answers: a line over the stage says who is ringing.
        await expect(ionWindow.locator('[data-holler-ringing]')).toContainText('Mihai Rusu')
        await expect(ionWindow.locator('.fi-holler-tile')).toHaveCount(1)

        const [mihaiWindow] = await Promise.all([
            mihai.context().waitForEvent('page'),
            mihai.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click(),
        ])

        // Everyone has a tile for each of the other two, with live audio and video.
        await expectPeers(ionWindow, ['Ana Popescu', 'Mihai Rusu'])
        await expectPeers(anaWindow, ['Ion Caliman', 'Mihai Rusu'])
        await expectPeers(mihaiWindow, ['Ana Popescu', 'Ion Caliman'])
        await expect(ionWindow.locator('.fi-holler-grid')).toHaveAttribute('data-count', '2')
        await expect.poll(() => state(mihaiWindow)).toBe('active')
        await expect(ionWindow.locator('.fi-holler-header .fi-holler-name')).toHaveText('Ana Popescu, Mihai Rusu')

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
        await expect(ionWindow.locator('.fi-holler-stage')).toHaveAttribute('data-strip', 'top')
        await expect(tile(anaWindow, 'Mihai Rusu')).toHaveAttribute('data-role', 'spotlight')
        const [spotlight, strip] = await Promise.all([tile(ionWindow, 'Mihai Rusu').boundingBox(), tile(ionWindow, 'Ana Popescu').boundingBox()])
        expect(spotlight.height).toBeGreaterThan(strip.height * 2)
        expect(strip.y).toBeLessThan(spotlight.y)
        // A wide window puts the strip on the right instead.
        await ionWindow.setViewportSize({ width: 900, height: 500 })
        await expect(ionWindow.locator('.fi-holler-stage')).toHaveAttribute('data-strip', 'right')
        await ionWindow.setViewportSize({ width: 460, height: 620 })
        await expect(ionWindow.locator('.fi-holler-stage')).toHaveAttribute('data-strip', 'top')
        await mihaiWindow.getByRole('button', { name: 'Stop sharing', exact: true }).click()
        await expect(ionWindow.locator('.fi-holler-stage')).not.toHaveAttribute('data-spotlight')
        await expect(tile(ionWindow, 'Mihai Rusu')).not.toHaveAttribute('data-role')

        // Ana reloads her window: both others see her back within seconds, Mihai included.
        await anaWindow.reload()
        await expectPeers(anaWindow, ['Ion Caliman', 'Mihai Rusu'])
        await expectPeers(ionWindow, ['Ana Popescu', 'Mihai Rusu'])
        await expectPeers(mihaiWindow, ['Ana Popescu', 'Ion Caliman'])

        // Mihai hangs up: his window closes, the other two go on.
        await Promise.all([
            mihaiWindow.waitForEvent('close'),
            mihaiWindow.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
        ])
        await expect(ionWindow.getByRole('status').filter({ hasText: 'Mihai Rusu left the call' })).toBeVisible()
        await expectPeers(ionWindow, ['Ana Popescu'])
        await expectPeers(anaWindow, ['Ion Caliman'])
        await expect.poll(() => state(ionWindow)).toBe('active')
        await expect.poll(() => state(anaWindow)).toBe('active')
        await expect.poll(() => state(mihai)).toBe('idle')
    } finally {
        await leaveCalls(mihai)
        await mihai.context().close()
    }
})

test('a call on hold: ring someone else, swap back with Resume, end the other call, and add someone from the call window', async ({ browser }) => {
    const mihai = await signIn(await browser.newContext(), 'mihai@example.com')

    try {
        const ionWindow = await ring(ion)
        const anaWindow = await answer(ana)
        await expectActive(ionWindow)
        await expectActive(anaWindow)

        // Call on Mihai during the call: the question, then hold and call. Ion's window moves on to the new call.
        await ion.getByRole('row', { name: /Mihai Rusu/ }).getByRole('button', { name: 'Call' }).click()
        await ion.locator('[data-holler-prompt]').getByRole('button', { name: 'Hold this call and start a new one' }).click()
        await expect(ion.locator('[data-holler-held]')).toContainText('Ana Popescu')
        await expect(ion.locator('[data-holler-held]')).toContainText('On hold')
        await expect.poll(() => state(ionWindow), { timeout: 30_000 }).toBe('calling')

        // Ana waits: her call is still there, and says why.
        await expect.poll(() => state(anaWindow)).toBe('waiting')
        await expect(anaWindow.locator('.fi-holler-header .fi-holler-status')).toHaveText('On hold')
        await expect(anaWindow.getByRole('status').filter({ hasText: 'Ion Caliman put the call on hold' })).toBeVisible()

        await expect(mihai.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
        const [mihaiWindow] = await Promise.all([
            mihai.context().waitForEvent('page'),
            mihai.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click(),
        ])
        await expectActive(ionWindow)
        await expectActive(mihaiWindow)
        await expect(ionWindow.locator('.fi-holler-header .fi-holler-name')).toHaveText('Mihai Rusu')
        // One call on hold at a time: nothing to hold in this one.
        await expect(ionWindow.locator('[data-holler-hold]')).toBeHidden()

        // Resume swaps the two: Ion is back with Ana, and Mihai waits.
        await ion.locator('[data-holler-resume]').click()
        await expectActive(ionWindow)
        await expectActive(anaWindow)
        await expect(ionWindow.locator('.fi-holler-header .fi-holler-name')).toHaveText('Ana Popescu')
        await expect.poll(() => state(mihaiWindow)).toBe('waiting')
        await expect(ion.locator('[data-holler-held]')).toContainText('Mihai Rusu')

        // The call on hold ended from its bar: Mihai is free again, Ion and Ana go on.
        await ion.locator('[data-holler-end-held]').click()
        await expect(ion.locator('[data-holler-held]')).toBeHidden()
        await expect.poll(() => state(mihai), { timeout: 30_000 }).toBe('idle')
        await expect.poll(() => state(ionWindow)).toBe('active')

        // Add someone from the call window itself: search, pick, and Mihai rings.
        await ionWindow.locator('[data-holler-add-person]').click()
        await expect(ionWindow.locator('[data-holler-add]')).toHaveText(['Mihai Rusu'].map((name) => new RegExp(name)))
        await ionWindow.locator('[data-holler-people-search]').fill('zzz')
        await expect(ionWindow.locator('[data-holler-no-people]')).toBeVisible()
        await ionWindow.locator('[data-holler-people-search]').fill('mih')
        await ionWindow.locator('[data-holler-add]', { hasText: 'Mihai Rusu' }).click()
        // The panel stays open: Mihai is in the list as Calling…, and no longer in the search.
        await expect(ionWindow.locator('[data-member]', { hasText: 'Mihai Rusu' })).toContainText('Calling')
        await expect(ionWindow.locator('[data-holler-add]')).toHaveCount(0)
        await expect(mihai.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
        await expect(ionWindow.locator('[data-holler-ringing]')).toContainText('Mihai Rusu')

        // Mihai says no: off the call, and back in the search.
        await mihai.getByRole('alertdialog').getByRole('button', { name: 'Decline', exact: true }).click()
        await expect(ionWindow.locator('[data-holler-ringing]')).toBeHidden()
        await expect(ionWindow.locator('[data-holler-add]', { hasText: 'Mihai Rusu' })).toBeVisible()

        // Hold from the call window too: it steps Ion out, and the bar shows on his page.
        await ionWindow.locator('[data-holler-hold]').click()
        await expect.poll(() => state(ionWindow)).toBe('idle')
        await expect(ion.locator('[data-holler-held]')).toContainText('Ana Popescu')
    } finally {
        await leaveCalls(mihai)
        await mihai.context().close()
    }
})

test('a pop-out link alone neither rings anyone nor answers a call', async () => {
    // Ana's id, from the Call button on Ion's Team page.
    const handler = await ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).evaluate((button) => button.outerHTML)
    const anaId = handler.match(/user: '([^']+)'/)[1]

    // Ion opens a window link that names Ana: it only polls, nobody rings.
    const link = await ion.context().newPage()
    await link.goto(`/admin/filament-holler/window?callee=${anaId}`)
    await expect(link.locator(WIDGET)).toBeAttached()
    // Loaded and polling first, then still idle a few polls later.
    await expect.poll(() => state(link)).toBe('idle')
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
    await anaLink.goto(`/admin/filament-holler/window?call=${callId}`)
    await expect(anaLink.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    await anaLink.waitForTimeout(2000)
    expect(await state(anaLink)).toBe('ringing')
    expect(await state(ionWindow)).toBe('calling')

    // A click on Accept is what answers.
    await anaLink.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click()
    await expectActive(anaLink)
    await expectActive(ionWindow)
})

test('the settings switch the microphone mid-call for the other side too, and remember the choice', async () => {
    const ionWindow = await ring(ion)
    const anaWindow = await answer(ana)
    await expectActive(ionWindow)
    await expectActive(anaWindow)

    const settings = ionWindow.getByRole('button', { name: 'Settings', exact: true })
    await expect(settings).toHaveAttribute('aria-expanded', 'false')
    await settings.click()
    await expect(settings).toHaveAttribute('aria-expanded', 'true')

    // The fake devices of the test browser: the default microphone and at least one more.
    const microphone = ionWindow.locator('[data-holler-device=microphone]')
    await expect.poll(() => microphone.locator('option').count()).toBeGreaterThan(1)
    await expect(microphone).toHaveValue('')
    const ids = (await microphone.locator('option').evaluateAll((options) => options.map((option) => option.value))).filter(Boolean)
    const sent = () => ionWindow.evaluate((selector) => document.querySelector(`${selector} video.fi-holler-local`).srcObject.getAudioTracks().map((track) => `${track.id}:${track.readyState}`), WIDGET)
    const before = await sent()

    await microphone.selectOption(ids.at(-1))
    await expect.poll(() => ionWindow.evaluate(() => JSON.parse(localStorage.getItem('holler:devices') ?? '{}').microphone ?? null)).toBe(ids.at(-1))
    // A new live track on Ion's side, the same connection on Ana's: nothing rang, nothing reconnected.
    await expect.poll(sent).not.toEqual(before)
    expect((await sent())[0]).toMatch(/:live$/)
    await expectActive(anaWindow)
    await expectActive(ionWindow)
    await expect(microphone).toHaveValue(ids.at(-1))

    // After a reload the call comes back with the remembered microphone, shown as chosen. Chromium's
    // private test context hands out new device ids on every load: there the remembered one is gone,
    // the system microphone takes over and the menu says so.
    await ionWindow.reload()
    await expectActive(ionWindow)
    await expectActive(anaWindow)
    await ionWindow.getByRole('button', { name: 'Settings', exact: true }).click()
    const again = ionWindow.locator('[data-holler-device=microphone]')
    await expect.poll(() => again.locator('option').count()).toBeGreaterThan(1)
    const listed = await again.locator('option').evaluateAll((options) => options.map((option) => option.value))
    await expect(again).toHaveValue(listed.includes(ids.at(-1)) ? ids.at(-1) : '')
})

test('a call that rings in a background tab shows in the tab title until it is answered or the person is back', async () => {
    await ana.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true })
        document.hasFocus = () => false
    })
    const title = await ana.title()

    const ionWindow = await ring(ion)
    await expect(ana.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    await expect.poll(() => ana.title()).toBe('Ion Caliman is calling you')
    await expect.poll(() => ana.title()).toBe(title)
    await expect.poll(() => ana.title()).toBe('Ion Caliman is calling you')

    // Ion gives up: the title is the page's own again.
    await Promise.all([
        ionWindow.waitForEvent('close'),
        ionWindow.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
    ])
    await expect.poll(() => state(ana)).toBe('idle')
    expect(await ana.title()).toBe(title)
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
    await expectActive(ionWindow)
    await expectActive(anaWindow)
    await expect(anaWindow).toHaveTitle(/^Ion Caliman · Call/)
    await expect(ana.getByRole('region', { name: 'Ion Caliman' })).toBeVisible()

    // Mute and unmute from the keyboard: the button that takes the clicked one's place has the focus.
    // The button that has the focus in the window, by its label: asked of the document, since a
    // headless pop-out is not always the system's focused window.
    const focused = () => anaWindow.evaluate(() => document.activeElement?.getAttribute('aria-label') ?? document.activeElement?.tagName ?? null)
    await anaWindow.bringToFront()
    await anaWindow.getByRole('button', { name: 'Mute', exact: true }).focus()
    await anaWindow.keyboard.press('Enter')
    await expect.poll(focused).toBe('Unmute')
    await anaWindow.keyboard.press('Enter')
    await expect.poll(focused).toBe('Mute')
    await anaWindow.getByRole('button', { name: 'Turn camera off', exact: true }).focus()
    await anaWindow.keyboard.press('Enter')
    await expect.poll(focused).toBe('Turn camera on')

    await Promise.all([
        ionWindow.waitForEvent('close'),
        anaWindow.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click(),
    ])
})

test('after a reload of the main tab, "Open the call window" brings the pop-out back without reloading it', async () => {
    const ionWindow = await ring(ion)
    await answer(ana)
    await expectActive(ionWindow)

    await ion.reload()
    await expect.poll(() => state(ion), { timeout: 30_000 }).toBe('elsewhere')
    await ionWindow.evaluate(() => { window.stillTheSamePage = true })

    await ion.locator('.fi-holler-chip').getByRole('button', { name: 'Open the call window', exact: true }).click()
    await ion.waitForTimeout(1000)
    expect(await ionWindow.evaluate(() => window.stillTheSamePage)).toBe(true)
    expect(await state(ionWindow)).toBe('active')
    expect(ion.context().pages()).toHaveLength(2)
})
