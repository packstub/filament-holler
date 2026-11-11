# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 12.69+ or 13.30+ |
| Filament | 4.13.3+ or 5.8.3+ |
| packstub/holler | ^1.0, installed by Composer |
| Browsers | any current one (WebRTC); the panel served over HTTPS, or `localhost`, since the camera and microphone need a secure context |

## Install

```bash
composer require packstub/filament-holler
php artisan migrate
php artisan filament:assets
```

The migration (the `calls`, `call_participants` and `call_signals` tables) comes from `packstub/holler` and runs from the package; there is nothing to publish. Run `php artisan filament:assets` again after every update of the package, so the browser gets the new widget.

## Register the plugin

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

That adds the call widget to every page of the panel for signed-in users, and the signaling routes under `/{panel}/filament-holler/calls/`, behind the panel's auth and CSRF middleware. On a panel that requires a verified email or multi-factor authentication, the routes and the widget ask for them the way the panel's own pages do.

Register it on every panel whose users should call each other. Each panel keeps its own calls: users of two panels on different guards never ring each other.

Then [add a Call button](calling.md#the-call-action) where your users are listed.

## Schedule the cleanup

The core's `holler:prune` command ends calls everybody walked away from and deletes ended ones older than `HOLLER_PRUNE_AFTER_DAYS` (90):

```php
// routes/console.php
Schedule::command('holler:prune')->daily();
```

## Try it on one machine

Sign in as two different people on two hostnames, for example `localhost` and `127.0.0.1`: cookies are per host, so one browser holds both sessions, and both count as secure contexts for the camera.
