# Customizing

## Icons

Every icon in the widget has an alias, so a panel can swap it with Filament's `FilamentIcon::register()`; the aliases are constants on `Packstub\FilamentHoller\View\FilamentHollerIconAlias`:

```php
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Packstub\FilamentHoller\View\FilamentHollerIconAlias;

FilamentIcon::register([
    FilamentHollerIconAlias::HANG_UP => Heroicon::OutlinedXCircle,
]);
```

| Alias | Where | Default |
| --- | --- | --- |
| `filament-holler::accept`, `::decline` | incoming call | phone, phone-x-mark |
| `filament-holler::hang-up` | call window and chip | phone-x-mark |
| `filament-holler::open-window` | chip | arrow-top-right-on-square |
| `filament-holler::mute`, `::unmute` | the button that mutes (shown while live), the one that unmutes (shown while muted) | microphone, a microphone with a slash |
| `filament-holler::camera-off`, `::camera-on` | the button that turns the camera off (shown while on), the one that turns it on | video-camera, video-camera-slash |
| `filament-holler::share-screen` | share and stop sharing | computer-desktop |
| `filament-holler::settings` | the button that opens the microphone, camera and speaker menus | cog-6-tooth |
| `filament-holler::muted`, `::sharing`, `::camera-off-badge` | badges over someone's video, and "Your mic is off" on your own | the microphone with a slash, computer-desktop, video-camera-slash |
| `filament-holler::weak-connection` | the badge over the video of someone whose connection is weak | signal |

## Texts and translations

The widget's labels ship in English, German, Romanian and Russian, and follow the panel's locale. Publish them to change a text or add a language:

```bash
php artisan vendor:publish --tag=filament-holler-translations
```

They land in `lang/vendor/filament-holler/en/calls.php`; copy the file to `lang/vendor/filament-holler/<locale>/calls.php` for another language, and the panel's locale picks it. The server's own messages ("This person is on another call.") come from `packstub/holler`:

```bash
php artisan vendor:publish --tag=holler-translations
```

## Engine options

The widget builds the engine (`CallClient` from [`@packstub/holler`](https://github.com/packstub/holler-js)) once per page. Just before, it fires `filament-holler-configure` on `window` with the options object as `detail`: whatever a script changes there is what the engine gets.

```js
window.addEventListener('filament-holler-configure', ({ detail: options }) => {
    options.topology = (call) => new MySfuTopology(call)   // a media server instead of the mesh
    options.reconnectTimeout = 30000
})
```

Register it before the widget loads, for example in a script added through `FilamentAsset::register()` or a `HEAD_END` render hook. The options are documented in the [`@packstub/holler` README](https://github.com/packstub/holler-js).
