# packstub/filament-calls

Free (MIT) Filament v4/v5 plugin: 1-on-1 audio/video/screen-share calls between panel users, peer to peer over
WebRTC. The panel side of `packstub/calls` (the sibling repo, `plugins/calls`), which owns the signaling backend:
tables, models, events, config (`packstub-calls`, `CALLS_*` env), the `Calls` service and facade, the
`CallContext` contract and `CallsController`. The browser engine is `@packstub/calls` (`plugins/calls-js`),
bundled here as `resources/dist/calls-core.js`. The two PHP packages share the `Packstub\Calls\` namespace
(Composer merges the PSR-4 directories), so class names must not collide across them. Split 2026-10-23;
private repo, untagged. Until `packstub/calls` is on Packagist, `composer.json` takes it from the path repo
`../calls` (pinned as 1.0.0): remove that `repositories` entry before the first tag.

## Commands

```bash
composer test               # Pest suite (feature: the six endpoints as two users, the widget, the action)
composer lint               # Pint
composer build:js           # npm run build:js: copies node_modules/@packstub/calls/src/index.js to resources/dist/calls-core.js
                            # (npm install first; the dev dependency points at github:packstub/calls-js; dist is committed)
composer serve              # workbench panel: Team page with Call buttons; ion@/ana@/mihai@example.com, password "password"
composer test:e2e           # Playwright: two people call each other through the workbench panel (tests/e2e/calls.spec.js);
                            # starts the server itself on port 8787 (CALLS_E2E_PORT), headless Chromium with a fake camera
                            # and microphone; `npx playwright install chromium` once
```

To try a call on one machine, sign in as two people on two hostnames (`localhost:8000` and `127.0.0.1:8000`):
cookies are per host, so one browser holds both sessions, and both hosts count as secure contexts for
`getUserMedia`. The workbench panel is non-SPA (`CALLS_SPA=1` turns SPA mode on) and follows the plugin's
pop-out default (`CALLS_POP_OUT=true|false` forces it). Automated Chrome needs
`--use-fake-ui-for-media-stream --use-fake-device-for-media-stream --use-file-for-fake-audio-capture=<wav>`:
without the wav, macOS asks Chrome for the microphone and `getUserMedia` hangs. Chrome also blocks `.click()`
pop-ups from `eval` unless launched with `--disable-popup-blocking` (chrome-devtools-axi does).

## Layout

- `resources/dist/calls-core.js`: a copy of `@packstub/calls` (`plugins/calls-js`, where the engine's own tests live). Never edit it here.
- `resources/dist/calls.js`: the Alpine component (`packstubCalls`), imports `../calls-core.js` relative to its
  published URL (Alpine components land in `components/`; the core is a `Js` asset `loadedOnRequest()` so it is published but not put in `<head>`).
  Draws state, plays the ring, attaches streams to the two `<video>` refs, shows the other side's mute, camera-off and
  screen-share badges (`peerstate`, `data-peer="…"`). When `window.open` returns null (pop-ups blocked) the call stays
  on the page with a notice. `watchWindow()`: a main tab in `elsewhere` whose call nobody holds any more takes it
  back (`client.takeOver()`, which looks at the server first) at once when it knows its pop-out closed, else after
  `TAKE_BACK_DELAY` (3 s, so a reloading pop-out keeps its side). `resources/dist/calls.css`: the widget.
- `resources/views/widget.blade.php`: rendered by the plugin's `BODY_END` render hook inside `@persist`; passes
  routes (`__ID__` placeholder for the call id), CSRF token, ICE servers, poll intervals and labels to Alpine.
- `src/FilamentCallsPlugin.php`: `canCall()`, `iceServers()`, `video()`, `popOut()` (null = pop out unless the
  panel has SPA mode); registers `Calls::routes(prefix: 'filament-calls/calls', middleware: [], as: 'filament-calls.')`
  and the window route inside `$panel->authenticatedRoutes()` (so they sit behind the panel's auth and CSRF),
  and the render hook (signed-in users only).
- `src/PanelContext.php`: the core's `CallContext` for a panel (bound by `FilamentCallsServiceProvider`, winning
  over the core's `AppContext`): `Filament::auth()`, `getUserName()` / `getUserAvatarUrl()`, and the plugin's
  options; falls back to `AppContext` outside a panel.
- Reconnect handshake (core): a page that loads mid-call polls, is handed the open call, claims it with `hold`,
  builds a new `RTCPeerConnection`, sends `reset {session}` then an offer; the other side rebuilds its
  connection on `reset` as the polite side and answers. Signals carry `session`; a mismatch is dropped.
  `pagehide` sends the `leave` beacon (FormData with `_token`, so the panel's CSRF check passes).
- `src/Http/WindowController.php` + `resources/views/window.blade.php`: the pop-out, Filament's base layout
  with an empty body (the widget arrives through the `BODY_END` hook and reads `?call=` / `?callee=`).
- `src/Actions/CallAction.php`: `alpineClickHandler` dispatching `filament-calls-call {user}` on `window`;
  visible only for another `Authenticatable`.
- `resources/lang/en/calls.php`: UI labels only (`filament-calls::calls.*`); server messages are the core's
  `calls::messages.*`. `workbench/`, `testbench.yaml` (registers both providers, the core's first).
- Tests: `WidgetTest`, `WindowTest` (pop-out, `popOut()`), `PanelRoutesTest` (the core routes under the panel,
  `canCall()` on the server, auth, CRLF through the panel route), `PanelContextTest`. The signaling logic
  itself is tested in `packstub/calls`; run that suite too after a change there. `tests/e2e/calls.spec.js`
  (Playwright, `composer test:e2e`) is the only test that runs the real thing: two browser contexts, the pop-outs,
  WebRTC over loopback, a reload mid-call, mute and screen share seen by the other side, the main tab taking the
  call back when its pop-out closes, and the page itself when `window.open` is blocked. Run it after a change in
  any of the three repos; the e2e job in `tests.yml` runs it too.

## Conventions

- User ids are strings everywhere (`caller_id`, `callee_id`, `to_user_id`, the JSON), so integer and UUID keys both work.
- Authorisation lives on the server: `canCall()` in `store`, `participant()` on every per-call endpoint. The
  action only renders a button.
- Anything Filament-specific goes in `resources/dist/calls.js` (the Alpine wrapper) or `src/`; the engine and
  the signaling logic belong to their own repos.
- Route names (`filament.{panel}.filament-calls.*`), the `CallContext` methods and the JSON shapes come from the
  core; they must not change inside a major.

## Not in the MVP (ideas)

Broadcast signaling adapter (Reverb/Echo) next to polling; a guest link for people outside the panel; a Calls
resource/relation manager for the log; a Co-Edit "call this person" hook; Document Picture-in-Picture for the
pop-out where supported.
