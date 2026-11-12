# The call

## The widget

The plugin draws one widget on every page of the panel for signed-in users:

- **Incoming call**: a toast with the caller's name and avatar, Accept and Decline, and a ring.
- **The call window**: a tile per other person (their video, or their avatar while the camera is off), your own picture in a corner, and the buttons to mute, turn the camera off, share your screen, open the settings and hang up. Everyone sees who muted, turned the camera off or shares a screen; a shared screen takes the stage, shown whole, with the cameras in a strip on top or on the side, wherever the screen gets more room.
- **Notices** ("Call declined", "Ringing Ana…") as Filament notifications.

It is drawn with Filament's own buttons, avatars and icons and follows the panel's theme, light or dark, its colours and the reading direction; only the video stays dark.

## Microphone, camera and speaker

The settings button in the call window opens three menus: the microphone, the camera and, where the browser can choose one (Chrome, Edge and recent Firefox), the speaker. A choice applies at once, for everyone on the call and without the call reconnecting, and is remembered in that browser for the next call; nothing about it is sent to the server. A remembered device that is unplugged later falls back to the system's own.

```php
FilamentHollerPlugin::make()->deviceSettings(false)   // no settings button
```

## A call in the background

The ring is easy to miss in a tab the person is not looking at, so while a call rings there the tab's title alternates with "Ana is calling you", until the call is answered, declined or given up, or the person is back on the tab.

A system notification needs the person's permission, and browsers only show that question after a click, so the plugin never asks by itself: the call's settings carry a link, "Notify me about calls when this tab is in the background". Once allowed, a ringing call shows one notification, however many tabs are open, and a click on it brings the tab back. A notification needs a tab of the panel to be open somewhere: the plugin runs no push service.

```php
FilamentHollerPlugin::make()->notifications(false)   // neither the title nor a notification
```

## Connection quality

While the connection to someone loses many packets or answers slowly, their picture carries a "Weak connection" badge, so people know why the video stutters. A call that never finds a path between the two browsers ends with "Could not connect", which usually means it needs a TURN server: see [ICE servers](configuration.md#ice-servers-stun--turn).

## Where the call lives

A browser closes a WebRTC connection on every full page load, so the plugin keeps the call somewhere a page load cannot reach.

**Pop-out window** (the default for panels without SPA mode). Call and Accept open the call in a small browser window of its own. The main tab shows a chip with the other person's name and the timer, a button to bring the window back and one to hang up, and is free to navigate, reload or close. Reloading the pop-out reconnects the call; closing it hands the call back to the main tab, which continues it in the page. If the browser blocks the pop-out, the call opens in the page instead, with a short notice.

The pop-out's address names the call (`…/filament-holler/window?call=12`) or the person to ring (`?callee=5`), but an address alone never rings anyone or answers a call: the page that opens the window leaves a one-time token in `localStorage` (valid for 60 seconds, read once) and the pop-out acts only when it finds it. Opened from a link or a bookmark, `?call=` shows the call with Accept when it is ringing you (or continues a call you are already on), and `?callee=` does nothing.

**In the page** (the default in SPA mode, `->spa()`). The widget is kept across `wire:navigate` page changes, so the call simply continues. A full page load (F5, a link outside the panel) reconnects it: the other side sees "Reconnecting…" for a few seconds, then the video is back.

Pick explicitly when the default is not what you want:

```php
FilamentHollerPlugin::make()
    ->popOut()        // always a pop-out, also in SPA mode
    ->popOut(false)   // always in the page; full page loads reconnect
    ->popOut(fn (): bool => /* any rule */ true)
```

`HOLLER_POP_OUT=false` turns the pop-out off for every panel without SPA mode that does not say otherwise. When one side stays away longer than `HOLLER_RECONNECT_TIMEOUT` (15 seconds by default) the call ends as lost.

## Keyboard and screen readers

Every control is a real button with a label and a tooltip; the mute, camera and share buttons say whether they are pressed, and the focus stays on them as they switch. An incoming call is announced ("Ion is calling you"), the call window and the chip are labelled regions named after the people on the call, and the pop-out's title says who the call is with.
