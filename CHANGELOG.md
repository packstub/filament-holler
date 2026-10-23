# Changelog

All notable changes to `packstub/filament-calls` are documented in this file.

## Unreleased

First release.

- `FilamentCallsPlugin`: six JSON signaling routes behind the panel's auth middleware and the call widget on every page (`BODY_END` render hook, kept across `wire:navigate` with `@persist`).
- `CallAction`: a client-side button that rings a user (the action's record, or `->user()`); hidden for the signed-in user.
- Call widget: incoming-call toast with ring tone, Accept and Decline; a call window with the other side's video, yours mirrored in a corner, a timer, mute, camera off, screen share (track replacement, back to the camera when sharing stops) and hang up; short notices for declined, cancelled, missed, busy and failed calls.
- Voice fallback when the camera is missing or busy; `video(false)` for voice-only panels.
- `canCall()` to decide who may call whom, checked on the server when the call starts; `iceServers()` and the `FILAMENT_CALLS_*` env variables for STUN and TURN.
- Pop-out window: Call and Accept open the call in a small window of its own (`/{panel}/filament-calls/window`), the main tab shows a chip (name, timer, bring the window back, hang up) and can navigate, reload or close; on by default for panels without SPA mode, `popOut()` / `popOut(false)` to decide per panel.
- Reconnecting: a full page load mid-call (or a reloaded pop-out) picks the call up again on the new page, the other side sees "Reconnecting…" meanwhile; a side away longer than `reconnect_timeout` (15 s) ends the call as `dropped`. Each window holds its side of a call (`caller_tab`/`callee_tab` and a last-seen time from polling), a `leave` beacon frees it on unload, a `reset` signal asks the other side for a fresh connection, and signals carry a session id so leftovers from an old connection are ignored.
- `calls` and `call_signals` tables; `Call` model with `duration()`; `CallStarted`, `CallAnswered`, `CallEnded` events.
- Polling signaling: one request per tab every 5 s idle and every 1 s during a call, with an id cursor so no signal is missed or repeated; stale ringing calls marked missed; a tab that reloads mid-call hangs that call up.
- `@packstub/calls` (`js/`): the framework-agnostic browser engine with a pluggable signaling adapter and `HttpSignaling`.
- Requires Filament 4.13.3+ or 5.8.3+.
