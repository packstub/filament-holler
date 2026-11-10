# Customizing

## Icons

Every icon in the widget has an alias, so a panel can swap it with Filament's `FilamentIcon::register()`; the aliases are constants on `Packstub\FilamentCalls\View\FilamentCallsIconAlias`:

```php
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Packstub\FilamentCalls\View\FilamentCallsIconAlias;

FilamentIcon::register([
    FilamentCallsIconAlias::HANG_UP => Heroicon::OutlinedXCircle,
]);
```

| Alias | Where | Default |
| --- | --- | --- |
| `filament-calls::accept`, `::decline` | incoming call | phone, phone-x-mark |
| `filament-calls::hang-up` | call window and chip | phone-x-mark |
| `filament-calls::open-window` | chip | arrow-top-right-on-square |
| `filament-calls::mute`, `::unmute` | the button that mutes (shown while live), the one that unmutes (shown while muted) | microphone, a microphone with a slash |
| `filament-calls::camera-off`, `::camera-on` | the button that turns the camera off (shown while on), the one that turns it on | video-camera, video-camera-slash |
| `filament-calls::share-screen` | share and stop sharing | computer-desktop |
| `filament-calls::muted`, `::sharing`, `::camera-off-badge` | badges over someone's video, and "Your mic is off" on your own | the microphone with a slash, computer-desktop, video-camera-slash |

## Texts and translations

The widget's labels ship in English. Publish them to change a text or add a language:

```bash
php artisan vendor:publish --tag=filament-calls-translations
```

They land in `lang/vendor/filament-calls/en/calls.php`; copy the file to `lang/vendor/filament-calls/<locale>/calls.php` for another language, and the panel's locale picks it. The server's own messages ("This person is on another call.") come from `packstub/calls`:

```bash
php artisan vendor:publish --tag=calls-translations
```

## Engine options

The widget builds the engine (`CallClient` from [`@packstub/calls`](https://github.com/packstub/calls-js)) once per page. Just before, it fires `filament-calls-configure` on `window` with the options object as `detail`: whatever a script changes there is what the engine gets.

```js
window.addEventListener('filament-calls-configure', ({ detail: options }) => {
    options.topology = (call) => new MySfuTopology(call)   // a media server instead of the mesh
    options.reconnectTimeout = 30000
})
```

Register it before the widget loads, for example in a script added through `FilamentAsset::register()` or a `HEAD_END` render hook. The options are documented in the [`@packstub/calls` README](https://github.com/packstub/calls-js).
