# Changelog

All notable changes to `packstub/filament-calls` are documented in this file.

## Unreleased

- The plugin lives in `Packstub\FilamentCalls\`, the core keeps `Packstub\Calls\`: one PSR-4 root per package, so class names cannot collide across the two.
- A pop-out link alone no longer rings anyone or answers a call: the page that opens the window leaves a one-time token in `localStorage` (60 s, read once) and the pop-out acts only with it. Without it, `?call=` shows the call with Accept (or continues one you are on) and `?callee=` only polls; the Blade view no longer passes `autoStart`.
- A pop-out that rang someone puts the call's id in its address as soon as the call exists (it used to wait for the next state change), so a reload continues the call.
- ICE servers are asked from the core's `ice` route each time a call starts instead of being written into every page: closures with short-lived TURN credentials run per call and the credentials stay out of the HTML. `CALLS_ICE_TRANSPORT_POLICY=relay` reaches the browsers.
- The widget polls only for calls of kind `call`, so another package's calls on the same tables (Session Replay's assist) never ring in it.
- Errors: a 401 or 419 (signed out, session expired) stops polling quietly; only a refusal the server explains (a 4xx with a `reason`) is shown, never a raw 500. A busy callee ends the attempt with "Busy" from the engine.
- The engine (`calls-core.js`) is imported with the component's own `?v=` version, so a release never pairs a new widget with a cached old engine.
- `filament-calls:configure` event on `window`, fired with the engine's options just before the engine is built: a script can set `topology` (an SFU), `reconnectTimeout` and the rest.
- Leaving the page takes the call down properly: `destroy()` closes the camera and the connections, the ring's audio context and the pop-out's close timer.
- The chip's clock counts from the answer, not from the ringing.
- `filament-calls-sharing` fires only when someone's screen starts or stops, not on every mute.
- A `$` in someone's name stays as it is in notices ("Ringing :name…", "+:count").
- The widget looks like the rest of the panel: Filament buttons, avatars and notifications, cards in the panel's light or dark theme with its colours (only the video stays dark), toggles as Filament's pressed buttons with an icon per state (the mute button no longer looks the same muted and unmuted), tooltips, right-to-left layouts, `aria-labelledby` on the incoming call.
- Icon aliases (`FilamentCallsIconAlias`, `filament-calls::hang-up` and eleven more) for `FilamentIcon::register()`.
- `PanelContext::findUser()` skips an id that cannot be a key of the panel's users (`abc` for an integer key) before the database, like the core, so Postgres never sees it.
- Engine updated from `@packstub/calls` (request timeouts, hidden-tab poll back-off, `ended 'missed'` when a call stops ringing as you answer, `iceTransportPolicy`, `destroy()`).
- The end-to-end suite checks that pop-out links neither ring nor answer, and waits out Filament's sign-in throttle.

First release. Built on `packstub/calls` (the signaling backend: tables, models, events, config, the `Calls` service and the `CallContext` contract, all of which lived here first) and `@packstub/calls` (the browser engine, moved to its own repo and bundled as `resources/dist/calls-core.js`).

- `FilamentCallsPlugin`: the core's signaling routes under `/{panel}/filament-calls/calls/`, behind the panel's auth middleware, and the call widget on every page (`BODY_END` render hook, kept across `wire:navigate` with `@persist`).
- `PanelContext`, bound as the core's `CallContext`: the panel's guard, `getUserName()` / `getUserAvatarUrl()` for how people appear to each other, and the plugin's `canCall()`, `iceServers()` and `video()`; outside a panel it answers like the core's `AppContext`.
- `CallAction`: a client-side button that rings a user (the action's record, or `->user()`); hidden for the signed-in user.
- Call widget: incoming-call toast with ring tone, Accept and Decline; a call window with the other side's video, yours mirrored in a corner, a timer, mute, camera off, screen share (track replacement, back to the camera when sharing stops) and hang up; short notices for declined, cancelled, missed, busy and failed calls.
- Voice fallback when the camera is missing or busy; `video(false)` for voice-only panels.
- `canCall()` to decide who may call whom, checked on the server when the call starts; `iceServers()` and the core's `CALLS_*` env variables (`config/packstub-calls.php`) for STUN and TURN.
- Pop-out window: Call and Accept open the call in a small window of its own (`/{panel}/filament-calls/window`), the main tab shows a chip (name, timer, bring the window back, hang up) and can navigate, reload or close; on by default for panels without SPA mode, `popOut()` / `popOut(false)` to decide per panel.
- A closed pop-out hands the call back: the main tab continues it in the page (at once when it saw the window close, within a few seconds otherwise; a reloading pop-out keeps its side) and says so; a pop-out closed before answering rings the main tab again.
- When the browser blocks the pop-out, the call opens in the page instead, with a notice, rather than nothing happening.
- A shared screen takes the stage in everyone else's window: its tile becomes the spotlight (shown whole, not cropped) and the other cameras shrink into a strip on top of a tall stage or on the right of a wide one, whichever leaves the screen the bigger picture; the layout follows the window as it resizes and goes back when the sharing stops. A `filament-calls-sharing` DOM event (`peer`, `sharing`, `spotlight`) fires on the widget for anything else on the page that wants to react.
- `PanelContext::findUsers()`: the roster of a call is named with one query per poll.
- The other side's mute, camera off and screen share show as badges over their video (the core's `state` signal); a camera they turned off shows their avatar instead of a black frame.
- A Playwright end-to-end suite (`composer test:e2e`): two people call each other through the workbench panel in headless Chromium with a fake camera and microphone, reload mid-call, mute and share, close the pop-out, and call with pop-ups blocked.
- Reconnecting: a full page load mid-call (or a reloaded pop-out) picks the call up again on the new page, the other side sees "Reconnecting…" meanwhile; a side away longer than `reconnect_timeout` (15 s) ends the call as `dropped`. Each window holds its side of a call (`caller_tab`/`callee_tab` and a last-seen time from polling), a `leave` beacon frees it on unload, a `reset` signal asks the other side for a fresh connection, and signals carry a session id so leftovers from an old connection are ignored.
- Polling signaling (from the core): one request per tab every 5 s idle and every 1 s during a call, with an id cursor so no signal is missed or repeated; stale ringing calls marked missed.
- Group calls, up to four people by default (`maxParticipants()` on the plugin, or the core's `CALLS_MAX_PARTICIPANTS`): while on a call, the same Call button adds that person to it, also from the main tab while the pop-out runs the call; everyone sees a tile per other participant (video or avatar, name, muted / camera off / sharing badges, "Calling…" until they answer), the incoming toast says how many more people are on the call, and someone who hangs up leaves the call for the others with a short notice. The engine connects everyone to everyone directly (a mesh); an SFU adapter can replace it through the engine's `topology` option for bigger rooms.
- The end-to-end suite adds a three-person call: the third person is added from the Team page, mutes, one of the others reloads, and one hangs up while the call goes on.
- Requires Filament 4.13.3+ or 5.8.3+.
