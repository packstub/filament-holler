// End-to-end: two signed-in people call each other through the workbench panel in headless
// Chromium with a fake camera and microphone. `composer test:e2e` (the server is started here).

import { defineConfig, devices } from '@playwright/test'
import { writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const PORT = Number(process.env.HOLLER_E2E_PORT || 8787)
const BASE_URL = `http://127.0.0.1:${PORT}`

/**
 * Without a file for the fake microphone, Chrome on macOS asks for the real one and
 * `getUserMedia` hangs: one second of silence (16 kHz, 16-bit mono).
 */
function silentWav() {
    const path = join(tmpdir(), 'filament-holler-e2e-mic.wav')
    const sampleRate = 16000
    const samples = sampleRate
    const data = Buffer.alloc(samples * 2)
    const header = Buffer.alloc(44)
    header.write('RIFF', 0)
    header.writeUInt32LE(36 + data.length, 4)
    header.write('WAVE', 8)
    header.write('fmt ', 12)
    header.writeUInt32LE(16, 16)
    header.writeUInt16LE(1, 20)
    header.writeUInt16LE(1, 22)
    header.writeUInt32LE(sampleRate, 24)
    header.writeUInt32LE(sampleRate * 2, 28)
    header.writeUInt16LE(2, 32)
    header.writeUInt16LE(16, 34)
    header.write('data', 36)
    header.writeUInt32LE(data.length, 40)
    writeFileSync(path, Buffer.concat([header, data]))

    return path
}

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 90_000,
    expect: { timeout: 20_000 },
    // One worker: the tests share the workbench database, and a person can only be on one call.
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    outputDir: 'test-results',

    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        permissions: ['camera', 'microphone'],
        launchOptions: {
            args: [
                '--use-fake-ui-for-media-stream',
                '--use-fake-device-for-media-stream',
                `--use-file-for-fake-audio-capture=${silentWav()}`,
                '--autoplay-policy=no-user-gesture-required',
            ],
        },
    },

    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

    webServer: {
        command: `php vendor/bin/testbench workbench:build && php vendor/bin/testbench serve --port=${PORT}`,
        url: `${BASE_URL}/admin/login`,
        reuseExistingServer: ! process.env.CI,
        timeout: 180_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
})
