// What both end-to-end specs share: signing in, reading the widget's state and media, ringing and answering.

import { expect } from '@playwright/test'

export const WIDGET = '[x-data*="packstubHoller"]'

export async function signIn(context, email) {
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
        if (attempt === 6) throw new Error(`Could not sign in as ${email}`)
        // The throttle's notification may not be on the page yet: without its number, wait a while anyway.
        const throttled = (await page.locator('body').innerText()).match(/try again in (\d+) seconds/i)
        await page.waitForTimeout(throttled ? (Number(throttled[1]) + 1) * 1000 : 15_000)
    }

    await page.goto('/admin/users')
    await expect(page.locator(WIDGET)).toBeAttached()

    return page
}

/** The Alpine component's state on a page: idle | calling | ringing | connecting | active | reconnecting | elsewhere. */
// Null until the page has loaded Alpine and the component (it is loaded on demand), so a poll waits for it.
export const state = (page) => page.evaluate((selector) => {
    const widget = document.querySelector(selector)

    return (widget && window.Alpine?.$data(widget)?.state) ?? null
    // A pop-out that is moving on to another call (hold and call, resume) has no page for a moment.
}, WIDGET).catch(() => null)

/** Kinds and states of the tracks on the remote <video>, e.g. ['audio:live', 'video:live']. */
export const remoteTracks = (page) => page.evaluate((selector) => {
    const video = document.querySelector(`${selector} video.fi-holler-remote`)

    return video?.srcObject ? video.srcObject.getTracks().map((track) => `${track.kind}:${track.readyState}`).sort() : []
}, WIDGET)

export const expectActive = async (page) => {
    await expect.poll(() => state(page), { timeout: 30_000 }).toBe('active')
    await expect.poll(() => remoteTracks(page)).toEqual(['audio:live', 'video:live'])
}

/** Ion rings Ana from her row on the Team page; the pop-out that opens is returned. */
export async function ring(ion) {
    const [window] = await Promise.all([
        ion.context().waitForEvent('page'),
        ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).click(),
    ])
    await expect(window).toHaveURL(/filament-holler\/window/)

    return window
}

/** Ana accepts from the toast on her page; her pop-out is returned. */
export async function answer(ana) {
    await expect(ana.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    const [window] = await Promise.all([
        ana.context().waitForEvent('page'),
        ana.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click(),
    ])

    return window
}

/** Hang up whatever this person is on or has on hold, and wait for the server to know. */
export const leaveCalls = (page) => page.evaluate(async (selector) => {
    const widget = document.querySelector(selector)
    if (! widget) return
    await Alpine.$data(widget).endHeld()
    await Alpine.$data(widget).hangUp()
}, WIDGET).catch(() => {})
