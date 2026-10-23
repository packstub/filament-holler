# Filament Calls

1-on-1 audio, video and screen-share calls inside a Filament panel, peer to peer over WebRTC. The media goes straight from one browser to the other; your Laravel app only handles who calls whom and passes the few signaling messages the browsers need to find each other. Polling over plain HTTP, so there is no websocket server to run.

- A `CallAction` for any record that is a user: a table row, a page header, an infolist.
- A call widget on every page of the panel: an incoming-call toast with Accept and Decline, then a floating window with the other person's video, yours in a corner, mute, camera off, screen share and hang up.
- The call follows the person around the panel: in its own small pop-out window (the main tab can go anywhere, reload or close), or in the page itself in SPA mode. A full page load mid-call reconnects within a few seconds instead of dropping it.
- Voice fallback when there is no camera; a voice-only mode per panel.
- `canCall()` to decide who may call whom; `CallStarted`, `CallAnswered`, `CallEnded` events and a `calls` table with durations for a call log.
- STUN out of the box, your own TURN server through four env variables for networks where a direct path fails (the relay only forwards encrypted packets).
- The browser side is [`@packstub/calls`](js/), a framework-agnostic ES module with a pluggable signaling adapter, so the same engine works outside Filament.

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

That adds the widget to every page of the panel and six JSON routes under `/{panel}/filament-calls/`, behind the panel's auth middleware.

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

### Voice calls

```php
FilamentCallsPlugin::make()->video(false)
```

Calls then start with the microphone only. Either side can still share the screen.

### Where the call lives

A browser tears down a WebRTC connection on every full page load, so the plugin keeps the call somewhere a page load cannot reach:

- **Pop-out window** (default for panels without SPA mode): Call and Accept open the call in a small browser window of its own. The main tab shows a chip with the other person's name and the timer, a button to bring the window back and one to hang up, and is free to navigate, reload or close. Reloading the pop-out itself reconnects the call.
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
FILAMENT_CALLS_STUN_URL=stun:stun.l.google.com:19302
FILAMENT_CALLS_TURN_URL=turn:turn.example.com:3478
FILAMENT_CALLS_TURN_USERNAME=...
FILAMENT_CALLS_TURN_CREDENTIAL=...
```

Or per panel, for example with short-lived TURN credentials:

```php
FilamentCallsPlugin::make()->iceServers(fn () => [
    ['urls' => 'turn:turn.example.com:3478', 'username' => $user, 'credential' => $password],
])
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is DTLS-SRTP end to end), so "the media never touches our servers" stays true in spirit; be accurate about it in your own privacy copy.

### Polling

Every open panel tab asks the server for incoming calls every 5 seconds and, during a call, for signals every second; that poll is also the heartbeat that tells the other side you are still there. Tune it in `config/filament-calls.php` (`FILAMENT_CALLS_POLL_IDLE`, `FILAMENT_CALLS_POLL_ACTIVE`, in milliseconds), `FILAMENT_CALLS_RING_TIMEOUT` (seconds before a call is marked missed, default 45) and `FILAMENT_CALLS_RECONNECT_TIMEOUT` (seconds a side may be away before the call ends as lost, default 15).

### Events and the call log

```php
use Packstub\Calls\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $event) {
    $event->call->caller_id;   // user ids as strings
    $event->call->callee_id;
    $event->call->end_reason;  // declined | cancelled | missed | hung_up | failed | dropped
    $event->call->duration();  // seconds, null if never answered
});
```

`Packstub\Calls\Models\Call` is a plain Eloquent model over the `calls` table, ready for a resource of your own.

## The browser engine

`js/` holds `@packstub/calls`: a `CallClient` (media, `RTCPeerConnection`, perfect negotiation, screen share by track replacement, reconnecting after a page load) that talks to any backend through a small signaling interface, plus `HttpSignaling`, the polling adapter this plugin uses. The Filament widget is a thin Alpine component over it. To use the engine elsewhere, implement the endpoints below (any language) and hand the client an adapter:

| Method | Route | Does |
| --- | --- | --- |
| `POST` | `calls` `{callee, tab}` | ring a user; 422 when not allowed, 409 when they are on a call |
| `POST` | `calls/{id}/answer` `{tab}` | callee joins; the call becomes `active` |
| `POST` | `calls/{id}/decline` | callee says no |
| `POST` | `calls/{id}/hangup` | either side ends it |
| `POST` | `calls/{id}/signals` `{type, payload}` | queue an `offer`, `answer`, `ice` or `reset` for the other side |
| `POST` | `calls/{id}/leave` `{tab}` | a beacon as the window unloads: the side is free for the next page |
| `GET` | `poll?after={cursor}&call={id}&tab={tab}&hold=1` | the call I am in (held by this window when `hold` is set: its heartbeat), an incoming call, and my new signals |

Every window has a random `tab` id; a side belongs to one window at a time, and a window that stops polling for 5 s loses it. A fresh page claims the side, sends `reset` and a new offer, and the other side builds a new connection for it.

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
