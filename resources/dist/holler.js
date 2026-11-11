/**
 * packstub/filament-holler — the call widget in the panel.
 *
 * A thin Alpine component over @packstub/holler (../holler-core.js: Filament publishes Alpine components one folder
 * deeper than plain JS assets): the core owns WebRTC and signaling, this file draws the state,
 * plays the ring and opens the pop-out window. Hand-written ES module, no build step (the core is
 * copied by `composer build:js`).
 *
 * The same component renders in three places: the panel's pages (incoming toast, the call window or,
 * when a pop-out holds the call, a small chip), and the pop-out page itself (config.popup).
 */

// The engine is imported with this file's own `?v=` query (Filament adds the package version to the
// component URL), so a new release never runs a new widget against a cached old engine. Top-level
// await is fine: Filament's x-load imports this module and waits for it.
const { CallClient, HttpSignaling } = await import(new URL('../holler-core.js', import.meta.url).href + new URL(import.meta.url).search)

const ACTIVE_STATES = ['calling', 'connecting', 'active', 'reconnecting']

// A pop-out that stopped holding the call for this long is gone, not reloading: the main tab takes the call back.
const TAKE_BACK_DELAY = 3000

// What the opening page leaves in localStorage for the pop-out: { call, callee, at }, read once.
const OPEN_TOKEN_KEY = 'holler:open:'
const OPEN_TOKEN_TTL = 60000

/** `:name` and the like in a label, taken literally (a `$` in someone's name stays a `$`). */
function fill(label, values) {
    return Object.entries(values).reduce((text, [key, value]) => text.replace(`:${key}`, () => String(value ?? '')), String(label ?? ''))
}

/**
 * Plain text for a Filament notification, whose title is HTML (sanitized, but a name like
 * `<a href=…>` would still come out as a link): every message here is text.
 */
function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char])
}

/**
 * What this pop-out was opened for. `trusted` only when the page that opened it left the
 * matching one-time token in localStorage: a link someone sent cannot ring anybody or
 * answer a call by itself.
 */
function readOpenedFor() {
    const query = new URLSearchParams(location.search)
    const opened = { call: query.get('call') || null, callee: query.get('callee') || null, trusted: false }
    const token = query.get('open')

    if (token) {
        try {
            const key = `${OPEN_TOKEN_KEY}${token}`
            const asked = JSON.parse(localStorage.getItem(key) ?? 'null')
            localStorage.removeItem(key)
            opened.trusted = Boolean(asked) && Date.now() - asked.at < OPEN_TOKEN_TTL
                && String(asked.call ?? '') === String(opened.call ?? '') && String(asked.callee ?? '') === String(opened.callee ?? '')
        } catch {
            opened.trusted = false
        }

        // Spent: a reload must not look as if it carried one.
        query.delete('open')
        history.replaceState(null, '', `${location.pathname}?${query}`)
    }

    return opened
}

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
        close() {
            this.stop()
            context?.close?.().catch?.(() => {})
            context = null
        },
    }
}

/** Tokens nobody used (the window was blocked or closed before it read one): gone after their minute. */
function sweepTokens() {
    for (let i = localStorage.length - 1; i >= 0; i--) {
        const key = localStorage.key(i)
        if (! key?.startsWith(OPEN_TOKEN_KEY)) continue
        try {
            if (Date.now() - JSON.parse(localStorage.getItem(key)).at > OPEN_TOKEN_TTL) localStorage.removeItem(key)
        } catch {
            localStorage.removeItem(key)
        }
    }
}

export default function packstubHoller(config) {
    let client = null
    let clock = null
    let popup = null
    let takeBackTimer = null
    let resizeObserver = null
    let closeTimer = null
    let clockSince = null
    // Who is sharing a screen, so `filament-holler-sharing` fires only when that changes (a mute is a peerstate too).
    const sharingPeers = new Map()
    const ring = ringer()

    const windowUrl = (params) => config.windowUrl.replace('__ID__', encodeURIComponent(params.call ?? '')).replace('__USER__', encodeURIComponent(params.callee ?? ''))

    return {
        state: 'idle',
        call: null,
        muted: false,
        cameraOff: false,
        sharing: false,
        // My own stream has a picture: false on a voice-only call (video(false), or no camera and the voice fallback).
        localVideo: false,
        // Everyone else on the call: { id, name, avatar, seat, status, alive, connected, muted, cameraOff, sharing, stream, hasVideo }.
        peers: [],
        // Where the cameras go while someone's screen has the stage: 'top' or 'right', whichever leaves the screen more room.
        strip: 'top',
        seconds: 0,
        notice: null,
        popup: Boolean(config.popup),
        popOut: Boolean(config.popOut) && ! config.popup,

        init() {
            const signaling = new HttpSignaling({
                routes: config.routes,
                headers: { 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                beaconFields: { _token: config.csrf },
                // Only calls: another package's calls (Session Replay's assist) never ring here.
                kind: config.kind ?? 'call',
            })

            const options = {
                signaling,
                // Asked from the `ice` route each time a call starts, so short-lived TURN credentials never go stale.
                iceServers: () => signaling.configuration().then((rtc) => rtc.iceServers),
                iceTransportPolicy: config.iceTransportPolicy === 'relay' ? 'relay' : 'all',
                video: config.video,
                poll: config.poll,
                reconnectTimeout: config.reconnectTimeout,
                maxParticipants: config.maxParticipants,
                // A tab that leaves calls to the pop-out never takes one over itself.
                resumes: ! this.popOut,
            }

            // The last word for the page: a script may change the options (a `topology` for an SFU, `channels`…).
            window.dispatchEvent(new CustomEvent('filament-holler-configure', { detail: options }))

            client = new CallClient(options)

            client.addEventListener('statechange', ({ detail: { state } }) => {
                this.state = state
                this.call = client.call
                this.sharing = client.sharing

                this.syncUrl()

                ring.stop()
                if (state === 'ringing') ring.play('ring')
                if (state === 'calling') ring.play('ringback')

                if (state === 'active' || (state === 'elsewhere' && client.call?.status === 'active')) this.startClock()
                else if (! ['reconnecting', 'connecting'].includes(state)) this.stopClock()
            })

            client.addEventListener('incoming', ({ detail: { call } }) => {
                this.call = call
                this.syncPeers()
            })
            client.addEventListener('update', ({ detail: { call } }) => {
                this.call = call
                // The chip's clock counts from the answer, not from the ringing.
                if (this.state === 'elsewhere' && call.status === 'active' && (! clock || (call.answered_at && clockSince === null))) {
                    this.stopClock()
                    this.startClock()
                }
                this.syncUrl()
                this.syncPeers()
                this.watchWindow()
            })
            client.addEventListener('localstream', ({ detail: { stream } }) => {
                this.localVideo = (stream?.getVideoTracks().length ?? 0) > 0
                this.attach(this.$refs.local, stream)
            })
            // A shared screen takes the stage: the look of every window on the call changes with it.
            client.addEventListener('peerstate', ({ detail: { peer, sharing } }) => {
                this.syncPeers()
                if ((sharingPeers.get(peer.id) ?? false) === sharing) return
                sharingPeers.set(peer.id, sharing)
                this.$dispatch('filament-holler-sharing', { peer, sharing, spotlight: this.spotlight })
            })
            // The roster, who is connected, muted or sharing, and whose media arrived: one event, the tiles follow.
            client.addEventListener('peers', () => this.syncPeers())
            client.addEventListener('remotestream', () => this.syncPeers())
            client.addEventListener('peerleft', ({ detail: { peer, reason } }) => {
                sharingPeers.delete(peer.id)
                const label = config.labels[`peer_${reason}`]
                if (label && this.peers.length > 0) this.show(fill(label, { name: peer.name }))
            })

            client.addEventListener('ended', ({ detail: { reason } }) => {
                this.peers = []
                this.sharing = false
                clearTimeout(takeBackTimer)
                takeBackTimer = null
                sharingPeers.clear()
                // A call someone hung up is news, not a problem; declined, missed, busy or failed is worth a warning.
                this.show(config.labels[`ended_${reason}`] || config.labels.ended_hung_up, ['hung_up', 'cancelled'].includes(reason) || ! config.labels[`ended_${reason}`] ? 'info' : 'warning')

                if (this.popup) closeTimer = setTimeout(() => window.close(), 1500)
            })

            client.addEventListener('error', ({ detail: { error, media } }) => {
                // Signed out, or the session expired: nothing to say every few seconds, just stop.
                if (error?.status === 401 || error?.status === 419) {
                    client.stop()
                    return
                }
                console.warn('[filament-holler]', error)
                if (media) this.show(config.labels.no_media)
                // The server's own words for a refusal it explains (not allowed, full); nothing raw from a crash.
                else if (error?.reason && error.status >= 400 && error.status < 500) this.show(error.message)
            })

            this.onCall = (event) => this.start(event.detail?.user)
            window.addEventListener('filament-holler-call', this.onCall)

            // A full page load: let go of the call so this or the next page can pick it up again.
            this.onLeave = () => client.leave()
            window.addEventListener('pagehide', this.onLeave)

            // The pop-out rings someone or answers only when the page that opened it asked (the token);
            // a link alone continues a call I am already in, or shows the one ringing me, with Accept.
            const opened = this.popup ? readOpenedFor() : null

            if (opened?.callee && opened.trusted) {
                client.start()
                client.callUser(String(opened.callee)).catch(() => {})
            } else if (opened?.call) {
                client.resumeCall(opened.call, { answer: opened.trusted })
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
            window.removeEventListener('filament-holler-call', this.onCall)
            window.removeEventListener('pagehide', this.onLeave)
            this.stopClock()
            clearTimeout(takeBackTimer)
            clearTimeout(closeTimer)
            clearTimeout(this.noticeTimer)
            ring.close()
            // Lets go of the call and closes the camera and the connections, without events.
            client?.destroy()
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
                this.show(fill(config.labels.inviting, { name: peer?.name ?? '' }), 'info')
            } catch (error) {
                // A refusal from the server was already shown by the error listener.
                if (error?.full) this.show(config.labels.call_full)
                else if (! error?.status) this.show(error?.message || config.labels.call_full)
            }
        },

        /**
         * The pop-out was opened to ring someone: once the call has an id its URL names the
         * call, so a reload continues it instead of ringing again.
         */
        syncUrl() {
            if (this.popup && client.call && new URLSearchParams(location.search).get('call') !== String(client.call.id)) {
                history.replaceState(null, '', windowUrl({ call: client.call.id }))
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

        /** My own corner shows my avatar instead of a dark box: camera off, or no picture at all, unless I share my screen. */
        get selfPlaceholder() {
            return (this.cameraOff || ! this.localVideo) && ! this.sharing
        },

        /** This person's video is worth showing: there is one, and it is not a camera they turned off. */
        showRemote(peer) {
            return peer.hasVideo && (! peer.cameraOff || peer.sharing)
        },

        /**
         * Open the pop-out; false when the browser blocked it (then the call stays on this page).
         * A one-time token in localStorage tells the pop-out that this page asked it to ring or to
         * answer: the same URL from anywhere else only shows the call.
         */
        openWindow(params) {
            const token = Math.random().toString(36).slice(2) + Date.now().toString(36)
            const key = `${OPEN_TOKEN_KEY}${token}`
            let url = windowUrl(params)
            try {
                sweepTokens()
                localStorage.setItem(key, JSON.stringify({ call: params.call ?? null, callee: params.callee ?? null, at: Date.now() }))
                url += `${url.includes('?') ? '&' : '?'}open=${token}`
            } catch {
                // No storage: the pop-out shows the call and waits for a click.
            }

            popup = window.open(url, 'filament-holler', 'popup=yes,width=460,height=620')
            popup?.focus()
            if (! popup) {
                try { localStorage.removeItem(key) } catch {}
            }

            return popup !== null
        },

        /** Bring the pop-out back; open it again if it was closed, or continue the call here when that is blocked. */
        focusWindow() {
            popup = popup && ! popup.closed ? popup : this.findWindow()
            if (popup) popup.focus()
            else if (this.call && ! this.openWindow({ call: this.call.id })) this.takeBack()
        },

        /**
         * The pop-out this tab opened before a reload: the reference is gone, the window is not.
         * Asked for by name with no address, so it is not navigated (that would reload the call);
         * a blank window means there was none, and openWindow() then uses that one.
         */
        findWindow() {
            try {
                const found = window.open('', 'filament-holler', 'popup=yes,width=460,height=620')
                const path = new URL(config.windowUrl, location.href).pathname

                return found && found.location.pathname === path ? found : null
            } catch {
                return null
            }
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
                if (await client.takeOver()) this.show(config.labels.window_closed_continuing, 'info')
            } finally {
                this.takingBack = false
            }
        },

        /**
         * Each toggle is two buttons, one per state, and the one clicked hides: the focus moves
         * to the one that takes its place, so a keyboard user keeps their spot.
         */
        keepFocus(name) {
            if (document.activeElement?.closest?.('[data-holler-toggle]')?.dataset.hollerToggle !== name) return
            this.$nextTick(() => [...this.$root.querySelectorAll(`[data-holler-toggle="${name}"]`)]
                .find((button) => button.style.display !== 'none')?.focus())
        },

        toggleMute() {
            this.keepFocus('mute')
            this.muted = ! this.muted
            client.setMuted(this.muted)
        },

        toggleCamera() {
            this.keepFocus('camera')
            this.cameraOff = ! this.cameraOff
            client.setCameraOff(this.cameraOff)
        },

        async toggleShare() {
            const focused = document.activeElement?.closest?.('[data-holler-toggle]')?.dataset.hollerToggle === 'share'
            try {
                if (client.sharing) await client.stopSharing()
                else await client.shareScreen()
            } catch {
                // The person cancelled the picker.
            }
            this.sharing = client.sharing
            if (focused) this.keepFocus('share')
        },

        attach(video, stream) {
            if (! video || video.srcObject === (stream ?? null)) return
            video.srcObject = stream ?? null
            if (stream) video.play?.().catch(() => {})
        },

        startClock() {
            if (clock) return
            clockSince = this.call?.answered_at ? Date.parse(this.call.answered_at) : null
            const since = clockSince ?? Date.now()
            this.seconds = Math.max(0, Math.floor((Date.now() - since) / 1000))
            clock = setInterval(() => this.seconds++, 1000)
        },

        stopClock() {
            clearInterval(clock)
            clock = null
            clockSince = null
            this.seconds = 0
        },

        /**
         * Tell the person something: a Filament notification where the page has them (every
         * panel page and the pop-out), the widget's own notice card otherwise.
         */
        show(text, status = 'warning') {
            if (! text) return

            if (window.FilamentNotification && document.querySelector('.fi-no')) {
                const notification = new window.FilamentNotification().title(escapeHtml(text))
                ;(status === 'info' ? notification.info() : notification.warning()).send()
                return
            }

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

        /** What a screen reader says when a call comes in ("Ion is calling you"). */
        get announcement() {
            return this.state === 'ringing' && this.call?.peer?.name ? fill(config.labels.incoming_from, { name: this.call.peer.name }) : ''
        },

        /** The pop-out's title: who the call is with. */
        get windowTitle() {
            return this.names ? `${this.names} · ${config.labels.call}` : config.labels.call
        },

        get othersLabel() {
            return fill(config.labels.and_more, { count: this.others })
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
