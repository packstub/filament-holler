# Configuration

The plugin's options (`canCall()`, `video()`, `maxParticipants()`, `popOut()`, `iceServers()`, `deviceSettings()`, `notifications()`) are set per panel and covered in [Calling](calling.md) and [The call](the-call.md). Everything else lives in `config/holler.php`, which belongs to `packstub/holler`. Each key reads an env variable, so most apps never publish the file; when you want to edit it:

```bash
php artisan vendor:publish --tag=holler-config
```

## ICE servers (STUN / TURN)

Two browsers need help to find a path to each other. A **STUN** server tells each one its public address, and for most calls that is enough: the media then goes straight from browser to browser. The package uses Google's public STUN server out of the box, free and without an account, so calls work right after the install:

```dotenv
HOLLER_STUN_URL=stun:stun.l.google.com:19302
```

Any other STUN server works the same way (`stun:stun.cloudflare.com:3478`, or your own), several can be listed with commas, and an empty value uses none. Public STUN servers come without a guarantee, so a production app that depends on calls usually runs its own beside the TURN server below (coturn does both).

Behind a strict NAT, a VPN or a corporate firewall no direct path exists, and the call needs a **TURN** relay that forwards the media. This is a minority of calls, but for the people on those networks it is every call. Point the package at a relay, your own [coturn](https://github.com/coturn/coturn) or a hosted service:

```dotenv
HOLLER_TURN_URL=turn:turn.example.com:3478,turns:turn.example.com:443?transport=tcp
HOLLER_TURN_SECRET=the-secret-coturn-shares-with-the-app
```

`HOLLER_TURN_URL` takes several addresses separated by commas. Listing a `turns:` address on port 443 over TCP beside the plain one is what gets a call through networks that only let web traffic out.

With `HOLLER_TURN_SECRET` every call gets a username and a credential that expire (after `HOLLER_TURN_TTL` seconds, 3600 by default), signed with the secret the TURN server shares with the app, so no long-lived password ever reaches a browser. It is coturn's `use-auth-secret` scheme:

```ini
# /etc/turnserver.conf
listening-port=3478
tls-listening-port=5349
realm=turn.example.com
use-auth-secret
static-auth-secret=the-secret-coturn-shares-with-the-app
cert=/etc/letsencrypt/live/turn.example.com/fullchain.pem
pkey=/etc/letsencrypt/live/turn.example.com/privkey.pem
```

A server with one fixed login takes `HOLLER_TURN_USERNAME` and `HOLLER_TURN_CREDENTIAL` instead of the secret.

A hosted TURN service (Cloudflare Realtime, Twilio, Metered and others) hands out short-lived credentials through its API. Ask for them in a closure and return what the browser should use:

```php
FilamentHollerPlugin::make()->iceServers(fn (): array => [
    ['urls' => 'stun:stun.example.com:3478'],
    ...Http::withToken(config('services.turn.token'))
        ->post(config('services.turn.url'), ['ttl' => 3600])
        ->throw()
        ->json('iceServers'),
])
```

The browsers ask for the servers each time a call starts (`GET /{panel}/filament-holler/calls/ice-servers`), never when the page loads: the closure runs per call, the credentials never sit in the page's HTML, and a tab left open all day still gets fresh ones. Any server in that list may carry `secret` (and `ttl`) instead of a username and credential, and is signed the same way; `Holler::turnCredentials($secret, $ttl)` returns the pair on its own.

ICE candidates show each side's network addresses to the other. To keep them private, send everything through your TURN server:

```dotenv
HOLLER_ICE_TRANSPORT_POLICY=relay
```

A TURN relay forwards packets it cannot decrypt (WebRTC media is encrypted end to end with DTLS-SRTP).

### When a call does not connect

A call that rings and is answered but never shows the other person ends after `HOLLER_RECONNECT_TIMEOUT` with "Could not connect" on both sides. When no TURN server is configured, the browser console says so too: that is nearly always the reason, since one of the two people is on a network that allows no direct path. Add a TURN server, with a `turns:` address on port 443 for the strictest networks. To check a TURN server on its own, set `HOLLER_ICE_TRANSPORT_POLICY=relay` for a moment: every call then has to go through it, also between two tabs on your own machine.

A call that connected and then ends with "Connection lost" had a path and lost it (a laptop that went to sleep, a network that dropped) for longer than the timeout. During a call a "Weak connection" badge on someone's picture means many packets to or from them are being lost or arrive late.

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

The other events are `CallStarted`, `CallAnswered`, `ParticipantJoined` and `ParticipantLeft`. `Packstub\Holler\Models\Call` is a plain Eloquent model over the `holler_calls` table, ready for a resource of your own.
