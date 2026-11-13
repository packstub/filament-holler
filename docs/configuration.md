# Configuration

The plugin's options (`canCall()`, `video()`, `maxParticipants()`, `popOut()`, `iceServers()`) are set per panel and covered in [Calling](calling.md) and [The call](the-call.md). Everything else lives in `config/holler.php`, which belongs to `packstub/holler`. Each key reads an env variable, so most apps never publish the file; when you want to edit it:

```bash
php artisan vendor:publish --tag=holler-config
```

## ICE servers (STUN / TURN)

Most calls connect directly with a STUN server, which is what the default config uses. Calls behind strict NATs, VPNs or corporate firewalls need a TURN relay; point the plugin at yours (coturn, or a hosted service):

```dotenv
HOLLER_STUN_URL=stun:stun.l.google.com:19302
HOLLER_TURN_URL=turn:turn.example.com:3478
HOLLER_TURN_USERNAME=...
HOLLER_TURN_CREDENTIAL=...
```

Or per panel, for example with short-lived TURN credentials:

```php
FilamentHollerPlugin::make()->iceServers(fn () => [
    ['urls' => 'turn:turn.example.com:3478', 'username' => $user, 'credential' => $password],
])
```

The browsers ask for these each time a call starts (`GET /{panel}/filament-holler/calls/ice-servers`), never when the page loads: a closure with short-lived credentials runs per call, the credentials never sit in the page's HTML, and a tab left open all day still gets fresh ones. `HOLLER_ICE_TRANSPORT_POLICY=relay` sends every call through the TURN server, so nobody learns anybody else's IP address.

A TURN relay forwards packets it cannot decrypt (WebRTC media is encrypted end to end with DTLS-SRTP).

## Polling and timeouts

Every open panel tab asks the server for incoming calls every 5 seconds and, during a call, for signals every second; that poll is also the heartbeat that tells the other side you are still there. Hidden tabs poll less often.

| Variable | Default | |
| --- | --- | --- |
| `HOLLER_POLL_IDLE` | 5000 | milliseconds between polls without a call |
| `HOLLER_POLL_ACTIVE` | 1000 | milliseconds between polls during a call |
| `HOLLER_RING_TIMEOUT` | 45 | seconds before a call nobody answers is marked missed |
| `HOLLER_RECONNECT_TIMEOUT` | 15 | seconds a side may be away before the call ends as lost |
| `HOLLER_MAX_PARTICIPANTS` | 4 | people on one call, the caller included; `maxParticipants()` overrides it |
| `HOLLER_VIDEO` | true | `false` makes every call a voice call |
| `HOLLER_POP_OUT` | true | the pop-out on panels without SPA mode; `popOut()` overrides it |
| `HOLLER_PRUNE_AFTER_DAYS` | 90 | ended calls older than this go at `holler:prune` |
| `HOLLER_DB_CONNECTION` | default | the central connection, under a database per tenant |

The limits on signals (`HOLLER_MAX_SIGNAL_KB`, `HOLLER_MAX_PENDING_SIGNALS`) and the rest of the file are documented in [Holler for Laravel](https://packstub.dev/docs/holler/configuration). Its `guard` key does not apply here: inside a panel the panel's guard is used.

## Events and the call log

```php
use Packstub\Holler\Events\CallEnded;

Event::listen(CallEnded::class, function (CallEnded $event) {
    $event->call->caller_id;     // user ids as strings
    $event->call->callee_id;     // the first person called
    $event->call->participants;  // everyone, with seat, status and times
    $event->call->end_reason;    // declined | cancelled | missed | hung_up | dropped
    $event->call->duration();    // seconds, null if never answered
});
```

The other events are `CallStarted`, `CallAnswered`, `ParticipantJoined` and `ParticipantLeft`. `Packstub\Holler\Models\Call` is a plain Eloquent model over the `calls` table, ready for a resource of your own.
