# Changelog

All notable changes to `packstub/filament-calls` are documented in this file.

## Unreleased

First release. Built on `packstub/calls` (the signaling backend: tables, models, events, config, the `Calls` service and the `CallContext` contract, all of which lived here first) and `@packstub/calls` (the browser engine, moved to its own repo and bundled as `resources/dist/calls-core.js`).

- `FilamentCallsPlugin`: the core's signaling routes under `/{panel}/filament-calls/calls/`, behind the panel's auth middleware, and the call widget on every page (`BODY_END` render hook, kept across `wire:navigate` with `@persist`).
- `PanelContext`, bound as the core's `CallContext`: the panel's guard, `getUserName()` / `getUserAvatarUrl()` for how people appear to each other, and the plugin's `canCall()`, `iceServers()` and `video()`; outside a panel it answers like the core's `AppContext`.
- `CallAction`: a client-side button that rings a user (the action's record, or `->user()`); hidden for the signed-in user.
- Call widget: incoming-call toast with ring tone, Accept and Decline; a call window with the other side's video, yours mirrored in a corner, a timer, mute, camera off, screen share (track replacement, back to the camera when sharing stops) and hang up; short notices for declined, cancelled, missed, busy and failed calls.
- Voice fallback when the camera is missing or busy; `video(false)` for voice-only panels.
- `canCall()` to decide who may call whom, checked on the server when the call starts; `iceServers()` and the core's `CALLS_*` env variables (`config/packstub-calls.php`) for STUN and TURN.
- Pop-out window: Call and Accept open the call in a small window of its own (`/{panel}/filament-calls/window`), the main tab shows a chip (name, timer, bring the window back, hang up) and can navigate, reload or close; on by default for panels without SPA mode, `popOut()` / `popOut(false)` to decide per panel.
- Reconnecting: a full page load mid-call (or a reloaded pop-out) picks the call up again on the new page, the other side sees "Reconnecting…" meanwhile; a side away longer than `reconnect_timeout` (15 s) ends the call as `dropped`. Each window holds its side of a call (`caller_tab`/`callee_tab` and a last-seen time from polling), a `leave` beacon frees it on unload, a `reset` signal asks the other side for a fresh connection, and signals carry a session id so leftovers from an old connection are ignored.
- Polling signaling (from the core): one request per tab every 5 s idle and every 1 s during a call, with an id cursor so no signal is missed or repeated; stale ringing calls marked missed.
- Requires Filament 4.13.3+ or 5.8.3+.
