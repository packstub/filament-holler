/**
 * An SPA panel without the pop-out (HOLLER_SPA=1 HOLLER_POP_OUT=false, the `chromium-spa` project on
 * a server of its own): the call runs on the page, and `@persist` keeps the widget, its connection
 * and its media while the person moves between pages with wire:navigate.
 */

import { expect, test } from '@playwright/test'
import { WIDGET, expectActive, leaveCalls, signIn, state } from './support.js'

let ion
let ana

test.beforeEach(async ({ browser }) => {
    ion = await signIn(await browser.newContext(), 'ion@example.com')
    ana = await signIn(await browser.newContext(), 'ana@example.com')
})

test.afterEach(async () => {
    for (const page of [ion, ana]) {
        if (page.isClosed()) continue
        await leaveCalls(page)
    }
    await ion.context().close()
    await ana.context().close()
})

/** Ion rings Ana from the Team page and she accepts from her toast: both on their pages, no window opened. */
async function talking() {
    await ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).click()
    await expect.poll(() => state(ion)).toBe('calling')
    await expect(ana.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    await ana.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click()
    await expectActive(ion)
    await expectActive(ana)
    expect(ion.context().pages()).toHaveLength(1)
    expect(ana.context().pages()).toHaveLength(1)
}

/** Mark the document and remember the remote stream, to tell a wire:navigate from a full page load afterwards. */
const mark = (page) => page.evaluate((selector) => {
    window.__hollerMark = document.querySelector(`${selector} video.fi-holler-remote`)?.srcObject ?? true
}, WIDGET)

/** Whether the page is the one marked (no full load) and still plays the same remote stream, live. */
const sameCall = (page) => page.evaluate((selector) => {
    const stream = document.querySelector(`${selector} video.fi-holler-remote`)?.srcObject

    return Boolean(window.__hollerMark) && stream === window.__hollerMark && stream.getTracks().every((track) => track.readyState === 'live')
}, WIDGET)

/** Follow a sidebar link with wire:navigate. */
async function go(page, label, url) {
    await page.getByRole('navigation').getByRole('link', { name: label, exact: true }).click()
    await page.waitForURL(url)
}

test('the call runs on the page and carries on while both people move between pages', async () => {
    await talking()
    await mark(ion)
    await mark(ana)

    await go(ion, 'Dashboard', '**/admin')
    await go(ana, 'Dashboard', '**/admin')
    await expect.poll(() => state(ion)).toBe('active')
    await expect.poll(() => sameCall(ion)).toBe(true)
    await expect.poll(() => sameCall(ana)).toBe(true)

    await go(ion, 'Team', '**/admin/users')
    await expect.poll(() => sameCall(ion)).toBe(true)
    await expect.poll(() => state(ana)).toBe('active')

    // Mute on one page is seen on the other side's page after its navigation.
    await ion.locator('.fi-holler-window').getByRole('button', { name: 'Mute', exact: true }).click()
    await expect(ana.locator('.fi-holler-tile [data-peer=muted]')).toBeVisible()

    await ana.locator('.fi-holler-window').getByRole('button', { name: 'Hang up', exact: true }).click()
    await expect.poll(() => state(ion)).toBe('idle')
    await expect(ion.getByRole('status').filter({ hasText: 'Call ended' })).toBeVisible()
})

test('a call rings on whatever page the person navigated to', async () => {
    await mark(ana)
    await go(ana, 'Dashboard', '**/admin')

    await ion.getByRole('row', { name: /Ana Popescu/ }).getByRole('button', { name: 'Call' }).click()
    await expect(ana.getByRole('alertdialog')).toContainText('Ion Caliman', { timeout: 30_000 })
    await ana.getByRole('alertdialog').getByRole('button', { name: 'Accept', exact: true }).click()
    await expectActive(ana)
    await expectActive(ion)
    expect(await ana.evaluate(() => window.__hollerMark === true)).toBe(true)
})

test('a session that ends mid-call ends the call with a notice, and turns the camera off', async () => {
    await talking()

    // Signed out in another tab: the session is gone, and the next poll is answered with 401.
    const status = await ion.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content
        const response = await fetch('/admin/logout', { method: 'POST', credentials: 'same-origin', redirect: 'manual', headers: { 'X-CSRF-TOKEN': token, Accept: 'text/html' } })

        return response.type === 'opaqueredirect' ? 302 : response.status
    })
    expect([200, 204, 302]).toContain(status)

    await expect(ion.getByText('You were signed out. Sign in again to call.')).toBeVisible({ timeout: 15_000 })
    await expect.poll(() => state(ion)).toBe('idle')
    await expect.poll(() => ion.evaluate((selector) => document.querySelector(`${selector} video.fi-holler-local`)?.srcObject?.getTracks().some((track) => track.readyState === 'live') ?? false, WIDGET)).toBe(false)
    // Ana's side loses Ion's connection rather than staying "active" with a frozen picture.
    await expect.poll(() => state(ana), { timeout: 30_000 }).not.toBe('active')
})
