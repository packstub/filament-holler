# The call

## The widget

The plugin draws one widget on every page of the panel for signed-in users:

- **Incoming call**: a toast with the caller's name and avatar, Accept and Decline, and a ring.
- **The call window**: a tile per other person (their video, or their avatar while the camera is off), your own picture in a corner, and the buttons to mute, turn the camera off, share your screen and hang up. Everyone sees who muted, turned the camera off or shares a screen; a shared screen takes the stage, shown whole, with the cameras in a strip on top or on the side, wherever the screen gets more room.
- **Notices** ("Call declined", "Ringing Ana…") as Filament notifications.

It is drawn with Filament's own buttons, avatars and icons and follows the panel's theme, light or dark, its colours and the reading direction; only the video stays dark.

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
