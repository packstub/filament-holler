# Filament Calls

Audio, video and screen-share calls inside a Filament panel, one on one or in small groups, peer to peer over WebRTC. The media goes straight from one browser to the other; your Laravel app only handles who calls whom and passes the few signaling messages the browsers need to find each other. Polling over plain HTTP, so there is no websocket server to run.

- A `CallAction` for any record that is a user: a table row, a page header, an infolist.
- A call widget on every page of the panel: an incoming-call toast with Accept and Decline, then a floating window with a tile per other person (their video, or their avatar), yours in a corner, mute, camera off, screen share and hang up. Everyone sees who muted, turned the camera off or shares a screen; a shared screen takes the stage in the other windows, shown whole, with the cameras in a strip on top or on the side, wherever the screen gets more room.
- Group calls up to four: on a call, the same Call button adds that person to it. The browsers connect to each other directly, no media server; someone who hangs up leaves the call for the others.
- The call follows the person around the panel: in its own small pop-out window (the main tab can go anywhere, reload or close), or in the page itself in SPA mode. A full page load mid-call reconnects within a few seconds instead of dropping it.
- Voice fallback when there is no camera; a voice-only mode per panel.
- `canCall()` to decide who may call whom; `CallStarted`, `CallAnswered`, `ParticipantJoined`, `ParticipantLeft`, `CallEnded` events and `calls` and `call_participants` tables with durations for a call log.
- STUN out of the box, your own TURN server through four env variables for networks where a direct path fails (the relay only forwards encrypted packets).
- Built on [Calls for Laravel](https://github.com/packstub/calls) (the signaling backend, installed with this package) and [`@packstub/calls`](https://github.com/packstub/calls-js) (the browser engine, bundled). Both work without Filament too.

## Requirements

- PHP 8.3+, Laravel 12 or 13, Filament 4.13.3+ or 5.8.3+
- Browsers with WebRTC (every current one); the panel served over HTTPS (or `localhost`), as `getUserMedia` needs a secure context

## Installation

```bash
composer require packstub/filament-calls
php artisan migrate
php artisan filament:assets
```

Register the plugin in your panel:

```php
use Packstub\Calls\FilamentCallsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            FilamentCallsPlugin::make(),
        ]);
}
```

That adds the widget to every page of the panel and the signaling routes under `/{panel}/filament-calls/calls/`, behind the panel's auth middleware. The tables, model, events and config file come from `packstub/calls`, which Composer installs alongside.

## Usage

Put a `CallAction` where the record is a user (an `Authenticatable`):

```php
use Packstub\Calls\Actions\CallAction;

// A table of users
->recordActions([
    CallAction::make(),
])

// A page header, calling a related user
protected function getHeaderActions(): array
{
    return [
        CallAction::make()->user(fn () => $this->record->assignee),
    ];
}
```

The button hides itself for the signed-in user. Clicking it rings the other person: their open panel tabs show a toast with the caller's name and avatar (the panel's own `getUserName()` / `getUserAvatarUrl()`), they accept, and the two browsers connect.

### Who may call whom

By default any two users of the panel can call each other. Narrow it:

```php
FilamentCallsPlugin::make()
    ->canCall(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id)
```

The check runs on the server when a call starts, not only when the button renders.

### Group calls

While on a call, clicking Call on another person adds them to it: they get the usual toast (with how many people are already on), and once they accept everyone connects to them. The window shows a tile per other participant, two by two. Four people on a call is the default limit; the browsers connect to each other directly, so each uploads its media once per other participant, and four is where that stops being comfortable on ordinary connections.

```php
FilamentCallsPlugin::make()->maxParticipants(3)   // or CALLS_MAX_PARTICIPANTS in .env
```

Someone who hangs up leaves the call for the others (the rest see "… left the call"); the call ends once one person is left. To go beyond four, the engine's `topology` option takes an SFU adapter in place of the mesh; see [`@packstub/calls`](https://github.com/packstub/calls-js).

### Voice calls

```php
FilamentCallsPlugin::make()->video(false)
```

Calls then start with the microphone only. Either side can still share the screen.

### Where the call lives

A browser tears down a WebRTC connection on every full page load, so the plugin keeps the call somewhere a page load cannot reach:

- **Pop-out window** (default for panels without SPA mode): Call and Accept open the call in a small browser window of its own. The main tab shows a chip with the other person's name and the timer, a button to bring the window back and one to hang up, and is free to navigate, reload or close. Reloading the pop-out itself reconnects the call; closing it hands the call back to the main tab, which continues it in the page. If the browser blocks the pop-out, the call opens in the page instead, with a short notice.
- **In the page** (default in SPA mode, `->spa()`): the widget is kept across `wire:navigate` page changes, so the call simply continues. A full page load (F5, a link outside the panel) reconnects it: the other side sees "Reconnecting…" for a few seconds, then the video is back.

Pick explicitly when the default is not what you want:

```php
FilamentCallsPlugin::make()
    ->popOut()        // always a pop-out, also in SPA mode
    ->popOut(false)   // always in the page; full page loads reconnect
    ->popOut(fn (): bool => ! request()->userAgent() /* or any rule */)
```

When one side stays away longer than `reconnect_timeout` (15 s by default) the call ends as lost.

### ICE servers (STUN / TURN)

Most calls connect directly with a STUN server, which is what the default config uses. Calls behind strict NATs, VPNs or corporate firewalls need a TURN relay; point the plugin at yours (coturn, or a hosted service):

```dotenv
CALLS_STUN_URL=stun:stun.l.google.com:19302
CALLS_TURN_URL=turn:turn.example.com:3478
CALLS_TURN_USERNAME=...
CALLS_TURN_CREDENTIAL=...
```

Or per panel, for example with short-lived TURN credentials:

```php
FilamentCallsPlugin::make()->iceServers(fn () => [
    ['urls' => 'turn:turn.example.com:3478', 'username' => $user, 'credential' => $password],
])
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is DTLS-SRTP end to end), so "the media never touches our servers" stays true in spirit; be accurate about it in your own privacy copy.

### Polling

Every open panel tab asks the server for incoming calls every 5 seconds and, during a call, for signals every second; that poll is also the heartbeat that tells the other side you are still there. Tune it in `config/packstub-calls.php` (`CALLS_POLL_IDLE`, `CALLS_POLL_ACTIVE`, in milliseconds), `CALLS_RING_TIMEOUT` (seconds before a call is marked missed, default 45) and `CALLS_RECONNECT_TIMEOUT` (seconds a side may be away before the call ends as lost, default 15).

### Events and the call log

```php
use Packstub\Calls\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $event) {
    $event->call->caller_id;     // user ids as strings
    $event->call->callee_id;     // the first person called
    $event->call->participants;  // everyone, with seat, status and times
    $event->call->end_reason;    // declined | cancelled | missed | hung_up | failed | dropped
    $event->call->duration();    // seconds, null if never answered
});
```

`Packstub\Calls\Models\Call` is a plain Eloquent model over the `calls` table, ready for a resource of your own.

## Outside Filament

The panel is a thin layer. [`packstub/calls`](https://github.com/packstub/calls) is the server side on its own (`Calls::routes()`, the `Calls` service, a `CallContext` for who is who and who may call whom) and [`@packstub/calls`](https://github.com/packstub/calls-js) the browser engine (`CallClient`, `HttpSignaling`), for a Laravel app without Filament or another backend speaking the same eight endpoints. This plugin binds a `PanelContext` that answers from the current panel (its guard, `getUserName()` / `getUserAvatarUrl()`, the plugin's `canCall()`, `iceServers()`, `video()` and `maxParticipants()`), registers the routes under the panel, and ships the widget, the `CallAction` and the pop-out window.

## Testing

```bash
composer test
composer serve   # workbench panel with a Team page; ion@/ana@/mihai@example.com, password "password"
```

Open the workbench twice, as two different people, on two hostnames (`localhost` and `127.0.0.1`) to try a call on one machine.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
