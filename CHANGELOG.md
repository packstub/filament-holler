# Changelog

All notable changes to `packstub/filament-calls` are documented in this file.

## Unreleased

First release: audio, video and screen-share calls between the users of a Filament v4 or v5 panel, 1-on-1 or in groups of up to four, peer to peer over WebRTC. Built on `packstub/calls` (the signaling backend: tables, models, events, config, the `Calls` service and the `CallContext` contract, all of which started here) and `@packstub/calls` (the browser engine, bundled as `resources/dist/calls-core.js`).

### The plugin

- `FilamentCallsPlugin`: the core's signaling routes and the pop-out page under `/{panel}/filament-calls/`, behind the panel's auth and CSRF, and the call widget on every page for signed-in users (`BODY_END` render hook, kept across `wire:navigate` with `@persist`).
- `canCall()` decides who may call whom, on the server when a call starts. By default anyone who can open the panel, and on a panel with tenancy only two people who share a tenant; a closure can take `bool $sharesTenant` to keep that rule, and `FilamentCallsPlugin::sharesTenant()` is public. The caller's tenants are read once per request, so a table of Call buttons does not read them per row.
- `iceServers()` for STUN and TURN per panel, asked from the core's `ice` route each time a call starts, so short-lived credentials stay fresh and out of the page's HTML; `video(false)` for voice-only panels; `maxParticipants()` (4 by default, never below 2); `popOut()` to choose where the call lives.
- `PanelContext`, bound as the core's `CallContext`: the panel's guard (users of panels on different guards never ring each other), `getUserName()` / `getUserAvatarUrl()`, the plugin's options, and one query to name a call's roster. Outside a panel it answers like the core's `AppContext`.
- `CallAction`: a client-side button that rings a user (the action's record, or `->user()`), shown only for people the signed-in user may call; on a call, it adds that person to it.

### The widget

- An incoming-call toast with ring tone, Accept and Decline (and how many people are already on); a call window with a tile per other participant (video or avatar, name, "Calling…" until they answer), your own picture mirrored in a corner, a timer, mute, camera off, screen share and hang up; notices for declined, missed, busy and failed calls and for people leaving a group.
- Everyone sees who muted, turned the camera off or shares the screen. A shared screen takes the stage, shown whole, with the cameras in a strip on top or on the side, wherever the screen gets more room; `filament-calls-sharing` fires on the page when that changes.
- Drawn with Filament's own buttons, avatars and icons, in the panel's light or dark theme and colours (only the video stays dark), right-to-left aware; messages are Filament notifications. Every icon has an alias (`FilamentCallsIconAlias`) for `FilamentIcon::register()`.
- Voice fallback when the camera is missing or busy.

### Where the call lives

- A pop-out window by default on panels without SPA mode: Call and Accept open the call in a small window of its own, and the main tab shows a chip (names, a timer counting from the answer, bring the window back, hang up) and can navigate, reload or close. Closing the pop-out hands the call back to the main tab, which continues it in the page; a reloading pop-out keeps its side. When the browser blocks the pop-out, the call opens in the page with a notice.
- A pop-out link alone never rings anyone or answers a call: the page that opens the window leaves a one-time token in `localStorage` (60 s, read once).
- In the page in SPA mode, and after a full page load mid-call the call reconnects within a few seconds while the other side sees "Reconnecting…"; a side away longer than `reconnect_timeout` (15 s) ends the call as dropped.
- The widget polls only for calls of kind `call`, so another package's calls on the same tables (Session Replay's assist, say) never ring in it. Signed out (401, 419) it stops polling quietly.
- `filament-calls-configure` fires on `window` with the engine's options just before the engine is built, so a script can set a `topology` (an SFU), `reconnectTimeout` and the rest. The engine is loaded with the component's own version, so a release never pairs a new widget with a cached old engine.

### Tests

- A Pest suite (the routes under the panel, `canCall()` on the server, the widget, the pop-out page, the context) and a Playwright end-to-end suite (`composer test:e2e`): two and three people call each other through the workbench panel in headless Chromium with a fake camera and microphone, reload mid-call, mute and share, close the pop-out, call with pop-ups blocked, and open a pop-out link without its token.
