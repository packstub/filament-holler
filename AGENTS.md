# packstub/filament-holler

Free (MIT) Filament v4/v5 plugin: audio/video/screen-share calls between panel users, 1-on-1 and groups up to
`maxParticipants()` (4), peer to peer over WebRTC (a mesh). The panel side of `packstub/holler` (the sibling repo, `plugins/holler`), which owns the signaling backend:
tables, models, events, config (`holler`, `HOLLER_*` env), the `Holler` service and facade, the
`CallContext` contract and `CallsController`. The browser engine is `@packstub/holler` (`plugins/holler-js`),
bundled here as `resources/dist/holler-core.js`. This package lives in `Packstub\FilamentHoller\`; the core keeps `Packstub\Holler\` (one PSR-4 root per
package, so names never collide across them). Split 2026-10-23; public repo, untagged (holler and holler-js are
public and tagged 1.0.0 since 2026-11-18). `packstub/holler` comes from Packagist (`^1.0`) and `@packstub/holler` from
npm (`^1.0`); to try uncommitted core work here, add a path repository to `../holler` for the session and drop it
before committing (`npm run build:js:local` does the same for the engine).

## Commands

```bash
composer test               # Pest suite (feature: the endpoints as two users, access rules, the widget, the action)
composer lint               # Pint
composer build:js           # npm run build:js (scripts/build.mjs, esbuild): minifies resources/js/holler.js and resources/css/holler.css into
                            # resources/dist and copies the engine's minified build to resources/dist/holler-core.js (npm install first;
                            # dist is committed). `npm run build:js:local` takes the engine from the checkout ../holler-js instead,
                            # `npm run build:js:check` fails when dist is stale (CI runs it)
composer serve              # workbench panel: Team page with Call buttons; ion@/ana@/mihai@example.com, password "password"
composer test:e2e:firefox   # the same suite in Firefox (its own WebRTC stack; `npx playwright install firefox` once); run it before a
                            # release. Playwright's WebKit has no fake camera: Safari is checked by hand
composer test:e2e           # Playwright: two people call each other through the workbench panel (tests/e2e/holler.spec.js);
                            # starts the server itself on port 8787 (HOLLER_E2E_PORT), headless Chromium with a fake camera
                            # and microphone; `npx playwright install chromium` once
```

To try a call on one machine, sign in as two people on two hostnames (`localhost:8000` and `127.0.0.1:8000`):
cookies are per host, so one browser holds both sessions, and both hosts count as secure contexts for
`getUserMedia`. The workbench panel is non-SPA (`HOLLER_SPA=1` turns SPA mode on) and follows the plugin's
pop-out default (`HOLLER_POP_OUT=true|false` forces it). To call from other devices and a phone, serve with
`HOLLER_HTTPS=1 PHP_CLI_SERVER_WORKERS=8 php vendor/bin/testbench serve --no-reload` and put
`cloudflared tunnel --url http://localhost:8000` in front: `HOLLER_HTTPS` makes the URLs https behind the tunnel
(`--no-reload` passes every env variable through, `HOLLER_TURN_*` too). Automated Chrome needs
`--use-fake-ui-for-media-stream --use-fake-device-for-media-stream --use-file-for-fake-audio-capture=<wav>`:
without the wav, macOS asks Chrome for the microphone and `getUserMedia` hangs. Chrome also blocks `.click()`
pop-ups from `eval` unless launched with `--disable-popup-blocking` (chrome-devtools-axi does).

The e2e suite (`chromium` for the pop-out panel, `chromium-spa` for an SPA panel without it, on a second server) is slow: run it before a release or after a change to the widget's behaviour, not after every edit.

## Layout

- `resources/dist/holler-core.js`: the minified build of `@packstub/holler` (`plugins/holler-js`, where the engine's own tests live). Never edit it here.
- `resources/js/holler.js` (minified into `resources/dist/holler.js`, which is what Filament publishes; edit the source, then `composer build:js`): the Alpine component (`packstubHoller`), imports `../holler-core.js` with a top-level
  `await import()` relative to its published URL and with its own `?v=` query (Alpine components land in `components/`;
  the core is a `Js` asset `loadedOnRequest()` so it is published but not put in `<head>`). Builds `HttpSignaling`
  (`kind: 'call'`, the `ice` route) and `CallClient` (`iceServers` a function asking `signaling.configuration()` per call),
  firing `filament-holler-configure` with the options first. `show()` sends a Filament notification (`window.FilamentNotification`,
  when `.fi-no` is on the page) and falls back to the notice card. Pop-out trust: `openWindow()` writes a one-time
  `holler:open:<token>` to localStorage and adds `&open=<token>`; `readOpenedFor()` in the pop-out rings or
  answers only with a matching token under 60 s old (same scheme as holler-js `widget.js`).
  Draws state, plays the ring, keeps `peers` (the engine's roster plus `hasVideo`) for one tile per other participant
  (`template x-for`, `data-peer-id`, `x-effect` attaches the stream, badges `data-peer="muted|sharing|camera-off"`,
  the name with "Calling…" / "Connecting…"). `start(user, name)` while on a call (or `elsewhere`, the pop-out running it)
  opens the question (`prompt`, `data-holler-prompt`): `promptAdd()` (`client.invite()`), `promptHold()` (`client.hold()`, then the new call) or
  `promptEnd()`. The call window has Add someone (`togglePeople()`, `loadPeople()` over `client.search()`, the `people` route; `pick()`) and
  Put on hold; a call on hold is `heldCall` with its bar (`data-holler-held`, `resumeHeld()` opens the pop-out for it, `endHeld()`). The engine's
  `canInvite()` / `canHold()` are not reactive: `syncCan()` copies them into `canInvite`, `canAdd`, `canHold` on every event that can change them. When `window.open` returns null (pop-ups blocked) the call stays
  on the page with a notice. `watchWindow()`: a main tab in `elsewhere` whose call nobody holds any more takes it
  back (`client.takeOver()`, which looks at the server first) at once when it knows its pop-out closed, else after
  `TAKE_BACK_DELAY` (3 s, so a reloading pop-out keeps its side). `resources/css/holler.css` (minified into `resources/dist/holler.css`): the widget.
- `resources/views/widget.blade.php`: rendered by the plugin's `BODY_END` render hook inside `@persist('filament-holler.<panel id>')` (one per panel); passes
  routes (`__ID__` placeholder for the call id, `ice` included), CSRF token, `kind`, `iceTransportPolicy`, poll intervals and labels
  to Alpine (no ICE servers: they are fetched per call). Drawn with `<x-filament::button>` (round via `fi-holler-round`, toggles as
  two `aria-pressed` buttons switched with `x-show`), `<x-filament::avatar>`, `<x-filament::icon>`, each icon with an alias from
  `src/View/FilamentHollerIconAlias.php`. `holler.css`: cards in the panel theme (white / gray-900), only the stage dark; Filament
  CSS variables only, no hex; logical properties.
- `src/FilamentHollerPlugin.php`: `canCall()`, `iceServers()`, `video()`, `maxParticipants()` (never below 2), `popOut()` (null = pop out unless the
  panel has SPA mode); registers `Holler::routes(prefix: 'filament-holler/calls', middleware: [], as: 'filament-holler.')`
  and the window route inside `$panel->authenticatedRoutes()` (so they sit behind the panel's auth and CSRF), plus
  `routeMiddleware()`: the panel's verified-email and MFA middleware where it requires them, as its pages get; the
  render hook (signed-in users, not on the verify-email / MFA set-up pages, not while an email the panel requires is
  unverified). `isCallAllowed()` also refuses a callee with an unverified email on such a panel.
- `src/PanelContext.php`: the core's `CallContext` for a panel (bound by `FilamentHollerServiceProvider`, winning
  over the core's `AppContext`): `Filament::auth()`, `getUserName()` / `getUserAvatarUrl()`, and the plugin's
  options (`maxParticipants()` included); falls back to `AppContext` outside a panel.
- Reconnect handshake (core): a page that loads mid-call polls, is handed the open call, claims it with `hold`,
  builds a new `RTCPeerConnection`, sends `reset {session}` then an offer; the other side rebuilds its
  connection on `reset` as the polite side and answers. Signals carry `session`; a mismatch is dropped.
  `pagehide` sends the `leave` beacon (FormData with `_token`, so the panel's CSRF check passes).
- `src/Http/WindowController.php` + `resources/views/window.blade.php`: the pop-out, Filament's base layout
  with an empty body (the widget arrives through the `BODY_END` hook and reads `?call=` / `?callee=` / `?open=` in JS).
- `src/Actions/CallAction.php`: `alpineClickHandler` dispatching `filament-holler-call {user}` on `window`;
  `resolveIsHiddenInGroup()` adds `canCallUser()` to whatever `visible()`/`hidden()` the app sets, so an app can
  narrow the button but never show it for someone `isCallAllowed()` refuses.
- The widget's settings (`deviceSettings()`: microphone, camera, speaker menus over `client.devices()` / `setDevice()`, remembered in localStorage `holler:devices`), the background-tab alert (`notifications()`: tab title, a system notification once allowed from the settings link) and the "Weak connection" badge (`peer.quality`) are drawn in `widget.blade.php` and driven by `resources/dist/holler.js`; the plain widget in `@packstub/holler` has the same three, so a change to one is usually a change to both. Toggles are two buttons each: `focusToggle()` waits for the new one to be on screen before focusing it (Firefox drops the focus otherwise).
- `resources/lang/{en,de,ro,ru}/calls.php`: the same keys in every language (a test checks). `en`: UI labels only (`filament-holler::calls.*`); server messages are the core's
  `holler::messages.*`. `workbench/`, `testbench.yaml` (registers both providers, the core's first).
- Tests: `WidgetTest`, `WindowTest` (pop-out, `popOut()`), `AccessTest` (canCall, tenancy, guard, verified email on the
  second fixture panel `verified`, MFA middleware, the action's visibility), `PanelRoutesTest` (the core routes under the panel,
  `canCall()` on the server, auth, CRLF through the panel route), `PanelContextTest`. The signaling logic
  itself is tested in `packstub/holler`; run that suite too after a change there. `tests/e2e/holler.spec.js`
  (Playwright, `composer test:e2e`) is the only test that runs the real thing: two browser contexts, the pop-outs,
  WebRTC over loopback, a reload mid-call, mute and screen share seen by the other side, the main tab taking the
  call back when its pop-out closes, the page itself when `window.open` is blocked, a pop-out link without the token (neither rings nor answers), and a three-person call (Mihai
  added from Ion's Team page while the pop-out runs the call, tiles with live tracks everywhere, a mute seen on
  the right tile, Ana's reload, Mihai hanging up while the call goes on), a name with markup in a notification, the
  keyboard and screen-reader pass (announcement, title, focus on toggles), and the chip's "Open the call window" after
  a main-tab reload finding the pop-out without reloading it. Run it after a change in
  any of the three repos; the e2e job in `tests.yml` runs it too.

## Conventions

- User ids are strings everywhere (`caller_id`, `callee_id`, `to_user_id`, the JSON), so integer and UUID keys both work.
- Authorisation lives on the server: `canCall()` in `store`, `participant()` on every per-call endpoint. The
  action only renders a button.
- Anything Filament-specific goes in `resources/js/holler.js` (the Alpine wrapper) or `src/`; the engine and
  the signaling logic belong to their own repos.
- Route names (`filament.{panel}.filament-holler.*`), the `CallContext` methods and the JSON shapes come from the
  core; they must not change inside a major.

## Release checklist

The three repos go out together as 1.0.0, in this order; the human does these steps (agents never tag, publish or change visibility). Each CHANGELOG keeps `## Unreleased` until then: rename it to `## 1.0.0 — <date>` in the release commit, and the tag's release workflow takes the notes from it. Keep the section short and human, since the workflow appends GitHub's list of merged pull requests under it: an optional lead sentence, then at most about six one-line bullets saying what a user gets, in plain words (class or method names only when a user needs the name, never lists of them). Always keep breaking changes with an `**Upgrading:**` line, security or privacy fixes, new minimum versions and migrations to run; CI, tests and refactors stay out.

1. **`packstub/holler-js`**: make the repo public; set `"version": "1.0.0"` in `package.json`; `npm pack --dry-run` (expect `src/` and `dist/` with `index.js`, `widget.js`, `widget.css` each, the two `.d.ts`, README, LICENSE, CHANGELOG); `npm publish` (`publishConfig.access` is public); tag `v1.0.0`.
2. **`packstub/holler`**: make the repo public; in `package.json` change the dev dependency `github:packstub/holler-js` to `^1.0`, `npm install`, `npm run build:js` and commit `resources/dist` (the engine's banner carries its version, so the three files change with 1.0.0 and nothing else should); tag `v1.0.0`; submit the repo to Packagist and check that the GitHub hook updates it.
3. **`packstub/filament-holler`** (this repo): remove the `repositories` path entry to `../holler` from `composer.json`; keep `"packstub/holler": "^1.0"` and run `composer update packstub/holler` so the lock takes it from Packagist (`vendor/packstub/holler` stops being a symlink); in `package.json` change `github:packstub/holler-js` to `^1.0`, `npm install`, `npm run build:js`, commit `resources/dist/holler-core.js` (its banner carries the new version) and check with `npm run build:js:check`; in `tests.yml` drop the checkout of `packstub/holler` and the two deploy keys, so CI tests against the released packages; `composer test` and `composer test:e2e`; make the repo public; tag `v1.0.0`; submit to Packagist.
4. Since step 3, CI installs `packstub/holler` from Packagist and `@packstub/holler` from npm, like any app: after a holler-js release, `npm update @packstub/holler && npm run build:js` here. Run the e2e suite in Firefox too before tagging (CI runs Chromium only: the pop-out panel and, as `chromium-spa`, the SPA panel without it).
5. Afterwards: update the three rows in the workspace `CLAUDE.md` (private, untagged, path repo), set `"stats": true` in `workspace/projects.json` and run `workspace/register-stats.sh`, the store listing and docs (skill `filament-plugin-listing`), and the filamentphp.com listing for this plugin (named **Holler** there).

## Not in the MVP (ideas)

Broadcast signaling adapter (Reverb/Echo) next to polling; a guest link for people outside the panel; a Calls
resource/relation manager for the log; a Co-Edit "call this person" hook; Document Picture-in-Picture for the
pop-out where supported.
