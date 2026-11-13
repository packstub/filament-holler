# Filament Holler

Audio, video and screen-share calls between the users of a Filament panel, one on one or in groups of up to four, peer to peer over WebRTC. The media goes straight from one browser to the other; your Laravel app only handles who calls whom and passes the few signaling messages the browsers need to find each other, over plain HTTP polling, so there is no websocket server to run. Free and open source (MIT).

- Repository: [github.com/packstub/filament-holler](https://github.com/packstub/filament-holler)
- Packagist: [packstub/filament-holler](https://packagist.org/packages/packstub/filament-holler)
- Support: [GitHub issues](https://github.com/packstub/filament-holler/issues)
- Built on: [Holler for Laravel](https://packstub.dev/docs/holler) (the signaling backend, installed with this package) and [`@packstub/holler`](https://github.com/packstub/holler-js) (the browser engine, bundled)

![Ion calls Ana from her row on the Team page, she answers, and the call window shows her video with his in the corner](https://raw.githubusercontent.com/packstub/art/main/filament-holler/docs/calls.gif)

## Features

- **[A Call button for any user](calling.md#the-call-action)**: `CallAction` on a table row, a page header or an infolist.
- **[A call widget on every page](the-call.md)**: an incoming-call toast with Accept and Decline, then a floating window with a tile per person, mute, camera, screen share and hang up, in the panel's theme, light or dark.
- **[Group calls](calling.md#group-calls)**: on a call, the same button adds someone to it, up to four people.
- **[Calls that follow you around](the-call.md#where-the-call-lives)**: in a pop-out window, or in the page itself in SPA mode; a reload reconnects within seconds.
- **[Who may call whom](calling.md#who-may-call-whom)**: `canCall()`, tenant-aware by default, checked on the server.
- **[STUN and TURN](configuration.md#ice-servers-stun--turn)**: STUN out of the box, your own TURN relay through four env variables or per panel.
- **[A call log](configuration.md#events-and-the-call-log)**: events and tables with durations, ready for a resource of your own.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, install, registering the plugin, assets, the cleanup command |
| [Calling](calling.md) | `CallAction`, who may call whom, tenancy, group calls, voice calls, page events |
| [The call](the-call.md) | The widget, the pop-out window and SPA mode, reconnecting, keyboard and screen readers |
| [Customizing](customizing.md) | Icons, texts and translations, engine options |
| [Configuration](configuration.md) | ICE servers, polling and timeouts, the config file, events and the call log |
