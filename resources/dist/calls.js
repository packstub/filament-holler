/**
 * packstub/filament-calls — the call widget in the panel.
 *
 * A thin Alpine component over @packstub/calls (../calls-core.js: Filament publishes Alpine components one folder
 * deeper than plain JS assets): the core owns WebRTC and signaling, this file draws the state,
 * plays the ring and opens the pop-out window. Hand-written ES module, no build step (the core is
 * copied by `composer build:js`).
 *
 * The same component renders in three places: the panel's pages (incoming toast, the call window or,
 * when a pop-out holds the call, a small chip), and the pop-out page itself (config.popup).
 */

import { CallClient, HttpSignaling } from '../calls-core.js'

const ACTIVE_STATES = ['calling', 'connecting', 'active', 'reconnecting']

// A ring (two short beeps) for the callee, a ringback (one long tone) for the caller.
function ringer() {
    let context = null
    let timer = null

    const beep = (frequency, start, length) => {
        const oscillator = context.createOscillator()
        const gain = context.createGain()
        oscillator.type = 'sine'
        oscillator.frequency.value = frequency
        gain.gain.setValueAtTime(0.0001, context.currentTime + start)
        gain.gain.exponentialRampToValueAtTime(0.15, context.currentTime + start + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + start + length)
        oscillator.connect(gain).connect(context.destination)
        oscillator.start(context.currentTime + start)
        oscillator.stop(context.currentTime + start + length + 0.05)
    }

    return {
        play(kind) {
            this.stop()
            try {
                context ??= new (window.AudioContext || window.webkitAudioContext)()
                context.resume().catch(() => {})
            } catch {
                return
            }
            const cycle = () => kind === 'ring' ? (beep(880, 0, 0.3), beep(880, 0.45, 0.3)) : beep(440, 0, 1.2)
            cycle()
            timer = setInterval(cycle, kind === 'ring' ? 2500 : 4000)
        },
        stop() {
            clearInterval(timer)
            timer = null
        },
    }
}

export default function packstubCalls(config) {
    let client = null
    let clock = null
    let popup = null
    const ring = ringer()

    const windowUrl = (params) => config.windowUrl.replace('__ID__', encodeURIComponent(params.call ?? '')).replace('__USER__', encodeURIComponent(params.callee ?? ''))

    return {
        state: 'idle',
        call: null,
        muted: false,
        cameraOff: false,
        sharing: false,
        remoteVideo: false,
        seconds: 0,
        notice: null,
        popup: Boolean(config.popup),
        popOut: Boolean(config.popOut) && ! config.popup,

        init() {
            client = new CallClient({
                signaling: new HttpSignaling({
                    routes: config.routes,
                    headers: { 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    beaconFields: { _token: config.csrf },
                }),
                iceServers: config.iceServers,
                video: config.video,
                poll: config.poll,
                reconnectTimeout: config.reconnectTimeout,
                // A tab that leaves calls to the pop-out never takes one over itself.
                resumes: ! this.popOut,
            })

            client.addEventListener('statechange', ({ detail: { state } }) => {
                this.state = state
                this.call = client.call
                this.sharing = client.sharing

                // The pop-out was opened to ring someone: from here on its URL names the call,
                // so a reload continues it instead of ringing again.
                if (this.popup && client.call && ! location.search.includes('call=' + client.call.id)) {
                    history.replaceState(null, '', windowUrl({ call: client.call.id }))
                }

                ring.stop()
                if (state === 'ringing') ring.play('ring')
                if (state === 'calling') ring.play('ringback')

                if (state === 'active' || state === 'elsewhere') this.startClock()
                else if (! ['reconnecting', 'connecting'].includes(state)) this.stopClock()
            })

            client.addEventListener('incoming', ({ detail: { call } }) => { this.call = call })
            client.addEventListener('update', ({ detail: { call } }) => { this.call = call })
            client.addEventListener('localstream', ({ detail: { stream } }) => this.attach(this.$refs.local, stream))
            client.addEventListener('remotestream', ({ detail: { stream } }) => {
                this.attach(this.$refs.remote, stream)
                this.remoteVideo = stream.getVideoTracks().length > 0
            })

            client.addEventListener('ended', ({ detail: { reason } }) => {
                this.remoteVideo = false
                this.sharing = false
                this.show(config.labels[`ended_${reason}`] || config.labels.ended_hung_up)

                if (this.popup) setTimeout(() => window.close(), 1500)
            })

            client.addEventListener('error', ({ detail: { error, media } }) => {
                console.warn('[filament-calls]', error)
                if (media) this.show(config.labels.no_media)
                else if (error?.status === 409) this.show(config.labels.ended_busy)
                else if (error?.status && error.status !== 404) this.show(error.message)
            })

            this.onCall = (event) => this.start(event.detail?.user)
            window.addEventListener('filament-calls-call', this.onCall)

            // A full page load: let go of the call so this or the next page can pick it up again.
            this.onLeave = () => client.leave()
            window.addEventListener('pagehide', this.onLeave)

            if (this.popup && config.autoStart?.callee) {
                client.start()
                client.callUser(String(config.autoStart.callee)).catch(() => {})
            } else if (this.popup && config.autoStart?.call) {
                client.resumeCall(config.autoStart.call, { answer: true })
            } else {
                client.start()
            }
        },

        destroy() {
            window.removeEventListener('filament-calls-call', this.onCall)
            window.removeEventListener('pagehide', this.onLeave)
            this.stopClock()
            ring.stop()
            client?.stop()
        },

        start(user) {
            if (! user) return

            if (this.popOut) {
                this.openWindow({ callee: user })
                return
            }

            client.callUser(String(user)).catch(() => {})
        },

        accept() {
            if (this.popOut) {
                this.openWindow({ call: this.call.id })
                client.handOff()
                return
            }

            client.accept().catch(() => {})
        },

        decline() { client.decline() },
        hangUp() { client.hangUp() },

        openWindow(params) {
            popup = window.open(windowUrl(params), 'filament-calls', 'popup=yes,width=460,height=620')
            popup?.focus()
        },

        /** Bring the pop-out back; open it again if it was closed. */
        focusWindow() {
            if (popup && ! popup.closed) popup.focus()
            else if (this.call) this.openWindow({ call: this.call.id })
        },

        toggleMute() {
            this.muted = ! this.muted
            client.setMuted(this.muted)
        },

        toggleCamera() {
            this.cameraOff = ! this.cameraOff
            client.setCameraOff(this.cameraOff)
        },

        async toggleShare() {
            try {
                if (client.sharing) await client.stopSharing()
                else await client.shareScreen()
            } catch {
                // The person cancelled the picker.
            }
            this.sharing = client.sharing
        },

        attach(video, stream) {
            if (! video) return
            video.srcObject = stream
            video.play?.().catch(() => {})
        },

        startClock() {
            if (clock) return
            const since = this.call?.answered_at ? Date.parse(this.call.answered_at) : Date.now()
            this.seconds = Math.max(0, Math.floor((Date.now() - since) / 1000))
            clock = setInterval(() => this.seconds++, 1000)
        },

        stopClock() {
            clearInterval(clock)
            clock = null
            this.seconds = 0
        },

        show(text) {
            this.notice = text
            clearTimeout(this.noticeTimer)
            this.noticeTimer = setTimeout(() => { this.notice = null }, 4000)
        },

        get inCall() {
            return ACTIVE_STATES.includes(this.state)
        },

        get elapsed() {
            const m = Math.floor(this.seconds / 60)
            const s = this.seconds % 60
            return `${m}:${String(s).padStart(2, '0')}`
        },

        get status() {
            if (this.state === 'calling') return config.labels.calling
            if (this.state === 'connecting') return config.labels.connecting
            if (this.state === 'reconnecting') return config.labels.reconnecting
            if (this.state === 'active') return this.elapsed
            if (this.state === 'elsewhere') {
                if (this.call?.held) return this.call?.status === 'active' ? this.elapsed : config.labels.calling
                return this.call?.status === 'ringing' ? config.labels.connecting : config.labels.window_closed
            }
            return ''
        },

        initials(name) {
            return String(name || '?').split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '').join('')
        },
    }
}
