# Filament Holler

<div class="filament-hidden">

![Filament Holler: a group video call in a floating window over a Filament Team page with a Call action on every row](https://raw.githubusercontent.com/packstub/art/main/filament-holler/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-holler.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-holler)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-holler/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-holler/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-holler.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-holler)
[![License](https://img.shields.io/packagist/l/packstub/filament-holler.svg?style=flat-square)](https://github.com/packstub/filament-holler/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

Audio, video and screen-share calls between the users of a Filament panel, one on one or in groups of up to four, peer to peer over WebRTC. The media goes straight from one browser to the other; your Laravel app only handles who calls whom, over plain HTTP polling, so there is no websocket server to run.

![Ion calls Ana from her row on the Team page, she answers, and the call window shows her video with his in the corner](https://raw.githubusercontent.com/packstub/art/main/filament-holler/docs/calls.gif)

## Features

- **[A Call button for any user](#the-call-action)**: `CallAction` on a table row, a page header or an infolist.
- **[A call widget on every page](#the-call-widget)**: an incoming-call toast, then a floating window with mute, camera, screen share and hang up, in the panel's theme.
- **[Group calls](#group-calls)**: add someone from a search box in the call window, or from their Call button, up to four people.
- **[Calls on hold](#calls-on-hold)**: put a call on hold, ring someone else and swap back with Resume.
- **[Calls that survive a page load](#where-the-call-lives)**: in a pop-out window, or in the page itself in SPA mode; a reload reconnects within seconds.
- **[Who may call whom](#who-may-call-whom)**: `canCall()`, tenant-aware by default, checked on the server.
- **[STUN and TURN](#stun-and-turn)**: a free public STUN server out of the box, your own TURN relay through env variables or per panel, with credentials that expire per call.
- **[Devices and notifications](#the-call-widget)**: people choose their microphone, camera and speaker mid-call, and a call in a background tab still gets noticed.
- **[Your icons and texts](#customizing)**: every icon has an alias, every label a translation, and the engine takes options.
- **[A call log](#events-and-the-call-log)**: events and tables with durations, ready for a resource of your own.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 4.13.3+, 5.8.3+ | 12.69+, 13.30+ | 8.3+ |

Calls run in every current browser (WebRTC). The panel must be served over HTTPS, or from `localhost`, since the camera and microphone need a secure context.

## Installation

```bash
composer require packstub/filament-holler
php artisan migrate
php artisan filament:assets
```

Register the plugin in your panel:

```php
use Packstub\FilamentHoller\FilamentHollerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            FilamentHollerPlugin::make(),
        ]);
}
```

That adds the call widget to every page of the panel and the signaling routes under `/{panel}/filament-holler/calls/`, behind the panel's auth. The tables, model, events and config file come from [Holler for Laravel](https://github.com/packstub/holler), which Composer installs alongside. Then schedule its cleanup:

```php
Schedule::command('holler:prune')->daily();
```

Read more: [Installation](https://packstub.dev/docs/filament-holler/installation).

## The Call action

Put a `CallAction` where the record is a user:

```php
use Packstub\FilamentHoller\Actions\CallAction;

// A table of users
->recordActions([
    CallAction::make(),
])

// A page header, calling a related user
CallAction::make()->user(fn () => $this->record->assignee)
```

The button only shows for someone the signed-in user may call, never for themselves. Clicking it rings the other person: their open panel tabs show a toast with the caller's name and avatar, they accept, and the two browsers connect.

Read more: [Calling](https://packstub.dev/docs/filament-holler/calling).

## The call widget

The plugin draws one widget on every page for signed-in users: the incoming-call toast with Accept and Decline, then a floating window with a tile per other person, your own picture in a corner, and buttons to mute, turn the camera off, share your screen and hang up. A shared screen takes the stage in the other windows. The settings button lets each person choose their microphone, camera and speaker, also mid-call, and a call that rings while the tab is in the background shows in the tab's title and, once allowed, as a system notification. The widget is drawn with Filament's own buttons and avatars and follows the panel's theme, light or dark; its messages are Filament notifications.

Read more: [The call](https://packstub.dev/docs/filament-holler/the-call).

## Who may call whom

By default any two users who can open the panel can call each other, and on a panel with tenancy only two who share a tenant. Narrow it:

```php
FilamentHollerPlugin::make()
    ->canCall(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id)
```

Add a `bool $sharesTenant` parameter to the closure to keep the tenant rule and add your own. The check runs on the server when a call starts and when someone is added to one, for every pair of people on the call. Someone who cannot open the panel, or has not verified the email the panel requires, is never callable.

Read more: [Who may call whom](https://packstub.dev/docs/filament-holler/calling#who-may-call-whom).

## Group calls

While on a call, the Add someone button in the call window finds people by name, and clicking Call on another person asks what to do: add them to the call, put the call on hold and ring them, or end it and ring them. Once the person accepts, everyone connects to them, and the window shows a tile per other participant. The browsers connect to each other directly, so four people is the default limit:

```php
FilamentHollerPlugin::make()->maxParticipants(3)   // or HOLLER_MAX_PARTICIPANTS in .env
```

Someone who hangs up leaves the call for the others. `->video(false)` makes the panel's calls voice calls; either side can still share their screen.

Read more: [Group calls](https://packstub.dev/docs/filament-holler/calling#group-calls).

## Calls on hold

Put on hold in the call window steps you out of a call without leaving it: the others stay on it and see that you put it on hold. The call waits in a small bar with Resume and End while you ring someone else, and Resume swaps the two calls. One call on hold at a time.

Read more: [Calls on hold](https://packstub.dev/docs/filament-holler/calling#calls-on-hold).

## Where the call lives

A browser closes a WebRTC connection on every full page load, so the plugin keeps the call where a page load does not end it. On panels without SPA mode, Call and Accept open the call in a small window of its own, and the main tab shows a chip with the timer while it navigates, reloads or closes. In SPA mode the call stays in the page across `wire:navigate`, and a full page load reconnects it within a few seconds.

```php
FilamentHollerPlugin::make()->popOut()        // always a pop-out, also in SPA mode
FilamentHollerPlugin::make()->popOut(false)   // always in the page
```

Read more: [Where the call lives](https://packstub.dev/docs/filament-holler/the-call#where-the-call-lives).

## STUN and TURN

Calls work right after the install: the default is Google's public STUN server, free and without an account (`HOLLER_STUN_URL` takes another one), and most calls then go straight from browser to browser. People on one network (an office, a VPN) need no server at all. People behind a strict NAT, a VPN or a corporate firewall need a TURN relay; point the plugin at yours:

```dotenv
HOLLER_TURN_URL=turn:turn.example.com:3478,turns:turn.example.com:443?transport=tcp
HOLLER_TURN_SECRET=the-secret-coturn-shares-with-the-app
```

With the secret (coturn's `use-auth-secret`) every call gets credentials that expire, so no long-lived TURN password reaches a browser; a fixed `HOLLER_TURN_USERNAME` and `HOLLER_TURN_CREDENTIAL` work too. For a hosted TURN service, return its credentials from `->iceServers(fn () => [...])` per panel: the browsers ask for the servers each time a call starts. `HOLLER_ICE_TRANSPORT_POLICY=relay` sends everything through the relay, so nobody sees anybody else's network address. A call that cannot find a path ends with "Could not connect" and a hint in the browser console.

Read more: [ICE servers](https://packstub.dev/docs/filament-holler/configuration#ice-servers-stun--turn).

## Customizing

Every icon in the widget has an alias for `FilamentIcon::register()`, the labels are translations (`php artisan vendor:publish --tag=filament-holler-translations`), and a `filament-holler-configure` event hands a script the engine's options before the widget starts, for example a media server in place of the mesh.

Read more: [Customizing](https://packstub.dev/docs/filament-holler/customizing).

## Events and the call log

`CallStarted`, `CallAnswered`, `ParticipantJoined`, `ParticipantLeft` and `CallEnded` fire for every step, and `Packstub\Holler\Models\Call` is a plain Eloquent model with its participants, `end_reason` and `duration()`, ready for a resource of your own.

Read more: [Events and the call log](https://packstub.dev/docs/filament-holler/configuration#events-and-the-call-log).

## Configuration

The plugin's options are set per panel:

```php
FilamentHollerPlugin::make()
    ->canCall(fn (User $caller, User $callee): bool => true)
    ->maxParticipants(4)
    ->video(true)
    ->popOut(null)               // null: a pop-out unless the panel runs in SPA mode
    ->deviceSettings(true)       // the microphone, camera and speaker menus
    ->notifications(true)        // a call in a background tab: the tab title, a system notification
    ->iceServers(fn () => [...]);
```

Everything else is in `config/holler.php`, from Holler for Laravel, and reads env variables: `HOLLER_POLL_IDLE`, `HOLLER_POLL_ACTIVE`, `HOLLER_RING_TIMEOUT`, `HOLLER_RECONNECT_TIMEOUT`, `HOLLER_PRUNE_AFTER_DAYS`, `HOLLER_DB_CONNECTION` and the STUN and TURN variables above.

Read more: [Configuration](https://packstub.dev/docs/filament-holler/configuration).

## Documentation

Full docs at [packstub.dev/docs/filament-holler](https://packstub.dev/docs/filament-holler):

- [Installation](https://packstub.dev/docs/filament-holler/installation)
- [Calling](https://packstub.dev/docs/filament-holler/calling): the Call action, who may call whom, tenancy, group calls, voice calls, page events
- [The call](https://packstub.dev/docs/filament-holler/the-call): the widget, the pop-out window and SPA mode, keyboard and screen readers
- [Customizing](https://packstub.dev/docs/filament-holler/customizing): icons, texts and translations, engine options
- [Configuration](https://packstub.dev/docs/filament-holler/configuration): ICE servers, polling and timeouts, events and the call log

The plugin is the panel side of [Holler for Laravel](https://packstub.dev/docs/holler), the signaling backend, and [`@packstub/holler`](https://github.com/packstub/holler-js), the browser engine. Both work without Filament too.

## Testing

```bash
composer test
composer test:e2e  # Playwright: real calls between headless Chromium windows (npx playwright install chromium once)
composer serve     # workbench panel with a Team page; ion@/ana@/mihai@example.com, password "password"
```

Open the workbench twice, as two different people, on two hostnames (`localhost` and `127.0.0.1`) to try a call on one machine.

## Changelog

See [CHANGELOG.md](https://github.com/packstub/filament-holler/blob/main/CHANGELOG.md).

## Security

If you discover a security issue, email [security@packstub.dev](mailto:security@packstub.dev) instead of using the issue tracker. See [SECURITY.md](https://github.com/packstub/filament-holler/blob/main/.github/SECURITY.md).

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/filament-holler/contributors)

## License

MIT. See [LICENSE.md](https://github.com/packstub/filament-holler/blob/main/LICENSE.md).
