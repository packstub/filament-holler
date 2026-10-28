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

// A pop-out that stopped holding the call for this long is gone, not reloading: the main tab takes the call back.
const TAKE_BACK_DELAY = 3000

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
    let takeBackTimer = null
    let resizeObserver = null
    const ring = ringer()

    const windowUrl = (params) => config.windowUrl.replace('__ID__', encodeURIComponent(params.call ?? '')).replace('__USER__', encodeURIComponent(params.callee ?? ''))

    return {
        state: 'idle',
        call: null,
        muted: false,
        cameraOff: false,
        sharing: false,
        // Everyone else on the call: { id, name, avatar, seat, status, alive, connected, muted, cameraOff, sharing, stream, hasVideo }.
        peers: [],
        // Where the cameras go while someone's screen has the stage: 'top' or 'right', whichever leaves the screen more room.
        strip: 'top',
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
                maxParticipants: config.maxParticipants,
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

            client.addEventListener('incoming', ({ detail: { call } }) => {
                this.call = call
                this.syncPeers()
            })
            client.addEventListener('update', ({ detail: { call } }) => {
                this.call = call
                this.syncPeers()
                this.watchWindow()
            })
            client.addEventListener('localstream', ({ detail: { stream } }) => this.attach(this.$refs.local, stream))
            // A shared screen takes the stage: the look of every window on the call changes with it.
            client.addEventListener('peerstate', ({ detail: { peer, sharing } }) => {
                this.syncPeers()
                this.$dispatch('filament-calls-sharing', { peer, sharing, spotlight: this.spotlight })
            })
            // The roster, who is connected, muted or sharing, and whose media arrived: one event, the tiles follow.
            client.addEventListener('peers', () => this.syncPeers())
            client.addEventListener('remotestream', () => this.syncPeers())
            client.addEventListener('peerleft', ({ detail: { peer, reason } }) => {
                const label = config.labels[`peer_${reason}`]
                if (label && this.peers.length > 0) this.show(label.replace(':name', peer.name))
            })

            client.addEventListener('ended', ({ detail: { reason } }) => {
                this.peers = []
                this.sharing = false
                clearTimeout(takeBackTimer)
                takeBackTimer = null
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

            if (this.$refs.stage && typeof ResizeObserver === 'function') {
                resizeObserver = new ResizeObserver(([entry]) => this.placeStrip(entry.contentRect.width, entry.contentRect.height))
                resizeObserver.observe(this.$refs.stage)
            }
        },

        /**
         * Which side the cameras go to while a screen has the stage: the one that leaves a
         * 16:9 screen the bigger picture. A strip on the right costs width, one on top height.
         */
        placeStrip(width, height) {
            if (! width || ! height) return
            const fit = (w, h) => Math.min(w, h * 16 / 9)
            const withRight = fit(width - Math.max(112, width * 0.22), height)
            const withTop = fit(width, height - Math.max(84, height * 0.22))
            this.strip = withRight > withTop ? 'right' : 'top'
        },

        destroy() {
            resizeObserver?.disconnect()
            window.removeEventListener('filament-calls-call', this.onCall)
            window.removeEventListener('pagehide', this.onLeave)
            this.stopClock()
            clearTimeout(takeBackTimer)
            ring.stop()
            client?.stop()
        },

        start(user) {
            if (! user) return

            // On a call already (here, or in my pop-out): the button adds the person to it.
            if (this.inCall || this.state === 'elsewhere') {
                this.add(user)
                return
            }

            // The pop-out, or the page itself when the browser blocks the window.
            if (this.popOut && this.openWindow({ callee: user })) return
            if (this.popOut) this.show(config.labels.popup_blocked)

            client.callUser(String(user)).catch(() => {})
        },

        accept() {
            if (this.popOut && this.openWindow({ call: this.call.id })) {
                client.handOff()
                return
            }
            if (this.popOut) this.show(config.labels.popup_blocked)

            client.accept().catch(() => {})
        },

        decline() { client.decline() },
        hangUp() { client.hangUp() },

        /** Add someone to the call I am on; the server rings them and everyone on the call connects to them. */
        async add(user) {
            if (! client.canInvite()) {
                this.show(config.labels.call_full)
                return
            }

            try {
                const call = await client.invite(String(user))
                const peer = call.peers?.find((candidate) => String(candidate.id) === String(user))
                this.show(config.labels.inviting.replace(':name', peer?.name ?? ''))
            } catch (error) {
                this.show(error?.full ? config.labels.call_full : (error?.message || config.labels.call_full))
            }
        },

        /** The peer whose screen has the stage: the first one sharing with video coming in, or null. */
        get spotlight() {
            return this.peers.find((peer) => peer.sharing && peer.hasVideo)?.id ?? null
        },

        /** A snapshot of the roster for the tiles. */
        syncPeers() {
            this.peers = client.peers.map((peer) => ({ ...peer, hasVideo: (peer.stream?.getVideoTracks().length ?? 0) > 0 }))
        },

        /** This person's video is worth showing: there is one, and it is not a camera they turned off. */
        showRemote(peer) {
            return peer.hasVideo && (! peer.cameraOff || peer.sharing)
        },

        /** Open the pop-out; false when the browser blocked it (then the call stays on this page). */
        openWindow(params) {
            popup = window.open(windowUrl(params), 'filament-calls', 'popup=yes,width=460,height=620')
            popup?.focus()

            return popup !== null
        },

        /** Bring the pop-out back; open it again if it was closed, or continue the call here when that is blocked. */
        focusWindow() {
            if (popup && ! popup.closed) popup.focus()
            else if (this.call && ! this.openWindow({ call: this.call.id })) this.takeBack()
        },

        /**
         * The pop-out let go of the call (closed, or never claimed it): after a moment, the call
         * continues on this page. A pop-out that is only reloading claims its side back within
         * that moment; a closed one is taken back at once.
         */
        watchWindow() {
            const orphaned = this.popOut && this.state === 'elsewhere' && this.call && ! this.call.held && this.call.status !== 'ended'

            if (! orphaned) {
                clearTimeout(takeBackTimer)
                takeBackTimer = null
                return
            }

            if (popup?.closed) {
                this.takeBack()
                return
            }

            takeBackTimer ??= setTimeout(() => {
                takeBackTimer = null
                this.takeBack()
            }, TAKE_BACK_DELAY)
        },

        async takeBack() {
            clearTimeout(takeBackTimer)
            takeBackTimer = null
            if (this.takingBack) return

            this.takingBack = true
            try {
                if (await client.takeOver()) this.show(config.labels.window_closed_continuing)
            } finally {
                this.takingBack = false
            }
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
            if (! video || video.srcObject === (stream ?? null)) return
            video.srcObject = stream ?? null
            if (stream) video.play?.().catch(() => {})
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

        /** Who is on the call with me, for the header and the chip. */
        get names() {
            const names = this.peers.map((peer) => peer.name)
            return names.length ? names.join(', ') : (this.call?.peer?.name ?? '')
        },

        /** On an incoming call: how many people are on it besides the one calling me. */
        get others() {
            return Math.max(0, this.peers.length - 1)
        },

        get othersLabel() {
            return config.labels.and_more.replace(':count', this.others)
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
