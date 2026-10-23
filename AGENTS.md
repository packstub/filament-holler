# packstub/filament-calls

Free (MIT) Filament v4/v5 plugin: 1-on-1 audio/video/screen-share calls between panel users, peer to peer over
WebRTC. Laravel only does signaling (who calls whom; offer, answer and ICE candidates relayed through a table)
over polled HTTP, no websockets. MVP as of 2026-10-23: untagged, repo not yet pushed.

## Commands

```bash
composer test               # Pest suite (feature: the six endpoints as two users, the widget, the action)
composer lint               # Pint
composer build:js           # copies js/src/index.js to resources/dist/calls-core.js (run after editing the core)
composer serve              # workbench panel: Team page with Call buttons; ion@/ana@/mihai@example.com, password "password"
```

To try a call on one machine, sign in as two people on two hostnames (`localhost:8000` and `127.0.0.1:8000`):
cookies are per host, so one browser holds both sessions, and both hosts count as secure contexts for
`getUserMedia`. The workbench panel is non-SPA (`CALLS_SPA=1` turns SPA mode on) and follows the plugin's
pop-out default (`CALLS_POP_OUT=true|false` forces it). Automated Chrome needs
`--use-fake-ui-for-media-stream --use-fake-device-for-media-stream --use-file-for-fake-audio-capture=<wav>`:
without the wav, macOS asks Chrome for the microphone and `getUserMedia` hangs. Chrome also blocks `.click()`
pop-ups from `eval` unless launched with `--disable-popup-blocking` (chrome-devtools-axi does).

## Layout

- `js/src/index.js`: `@packstub/calls`, the framework-agnostic browser engine (future npm package). `CallClient`
  owns the streams and the `RTCPeerConnection` (perfect negotiation, callee polite; screen share via
  `replaceTrack`; voice fallback when no camera) and talks to a `signaling` object: `start`, `answer`, `decline`,
  `hangup`, `send`, `poll`. `HttpSignaling` is the polling adapter. No DOM, no Alpine, no Laravel in this file.
- `resources/dist/calls.js`: the Alpine component (`packstubCalls`), imports `../calls-core.js` relative to its
  published URL (Alpine components land in `components/`; the core is a `Js` asset `loadedOnRequest()` so it is published but not put in `<head>`).
  Draws state, plays the ring, attaches streams to the two `<video>` refs. `resources/dist/calls.css`: the widget.
- `resources/views/widget.blade.php`: rendered by the plugin's `BODY_END` render hook inside `@persist`; passes
  routes (`__ID__` placeholder for the call id), CSRF token, ICE servers, poll intervals and labels to Alpine.
- `src/FilamentCallsPlugin.php`: `canCall()`, `iceServers()`, `video()`, `popOut()` (null = pop out unless the
  panel has SPA mode); registers the routes with `$panel->authenticatedRoutes()` (so they sit behind the
  panel's auth and CSRF) and the render hook (signed-in users only).
- Reconnect handshake (core): a page that loads mid-call polls, is handed the open call, claims it with `hold`,
  builds a new `RTCPeerConnection`, sends `reset {session}` then an offer; the other side rebuilds its
  connection on `reset` as the polite side and answers. Signals carry `session`; a mismatch is dropped.
  `pagehide` sends the `leave` beacon (FormData with `_token`, so the panel's CSRF check passes).
- `src/Http/CallsController.php`: `store`, `answer`, `decline`, `hangup`, `signal`, `leave`, `poll`. `poll` returns
  the call this window named (or any open call the server still has me in, for a page that just loaded), an
  incoming ringing call, and the signals for the held call with `id > after`. Naming the call with `hold=1`
  claims that side for the window's `tab` (`Call::claim()`, one atomic UPDATE: free, its own, or stale for
  `HOLD_SECONDS`) and is its heartbeat; a tab that only watches (the chip) polls without `hold`. Taking over
  from a window that left deletes the signals meant for the old connection. Stale ringing calls are marked
  `missed`, active calls with a side unseen for `reconnect_timeout` are marked `dropped`, both here.
- `src/Http/WindowController.php` + `resources/views/window.blade.php`: the pop-out, Filament's base layout
  with an empty body (the widget arrives through the `BODY_END` hook and reads `?call=` / `?callee=`).
- `src/Models/Call.php` (`ringing | active | ended`, `end_reason`, `*_tab` / `*_seen_at` per side, `claim()`,
  `release()`, `isHeld()`, `duration()`, `end()` deletes the signals), `src/Models/CallSignal.php`
  (`offer | answer | ice | reset`).
- `src/Actions/CallAction.php`: `alpineClickHandler` dispatching `filament-calls-call {user}` on `window`;
  visible only for another `Authenticatable`.
- `src/Calls.php`: `who()` (panel user name and avatar), `findUser()` through the panel guard's provider.
- `src/Events/{CallStarted,CallAnswered,CallEnded}.php`, `config/filament-calls.php`, `resources/lang/en`.
- `database/migrations/create_calls_table.php` (loaded by the package, `runsMigrations()`), `workbench/`,
  `testbench.yaml`.

## Conventions

- User ids are strings everywhere (`caller_id`, `callee_id`, `to_user_id`, the JSON), so integer and UUID keys both work.
- Authorisation lives on the server: `canCall()` in `store`, `participant()` on every per-call endpoint. The
  action only renders a button.
- Keep `js/src/index.js` free of framework code; anything Filament-specific goes in `resources/dist/calls.js`.

## Not in the MVP (ideas)

Broadcast signaling adapter (Reverb/Echo) next to polling; a guest link for people outside the panel; a Calls
resource/relation manager for the log; a Co-Edit "call this person" hook; the main tab taking a call back
inline when its pop-out is closed (today the other side waits out `reconnect_timeout`); Document
Picture-in-Picture for the pop-out where supported.
