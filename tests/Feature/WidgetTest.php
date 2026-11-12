<?php

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentIcon;
use Packstub\FilamentHoller\Actions\CallAction;
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Packstub\FilamentHoller\Tests\Fixtures\ListUsers;
use Packstub\FilamentHoller\View\FilamentHollerIconAlias;

use function Pest\Livewire\livewire;

it('renders the call widget on every panel page with the panel routes, and no ICE servers in the page', function (): void {
    FilamentHollerPlugin::get()->iceServers(fn () => throw new RuntimeException('ICE servers are asked per call, not per page.'));

    // The config travels through @js (json_encode inside a JS string), which escapes each slash to \\\/.
    $js = fn (string $url): string => str_replace('\\', '\\\\\\', trim(json_encode($url), '"'));

    $this->actingAs(user('Ion'))->get('/admin')
        ->assertOk()
        ->assertSee('packstubHoller(', escape: false)
        ->assertDontSee('iceServers')
        ->assertSee($js(url('/admin/filament-holler/calls/ice-servers')), escape: false)
        ->assertSee('\\u0022iceTransportPolicy\\u0022:\\u0022all\\u0022', escape: false)
        ->assertDontSee('autoStart')
        ->assertSee('\\u0022kind\\u0022:\\u0022call\\u0022', escape: false)
        ->assertSee($js(url('/admin/filament-holler/calls/poll')), escape: false)
        ->assertSee($js(url('/admin/filament-holler/calls/__ID__/answer')), escape: false)
        ->assertSee($js(url('/admin/filament-holler/calls/__ID__/invite')), escape: false)
        ->assertSee('\\u0022maxParticipants\\u0022:4', escape: false)
        ->assertSee('data-peer-id', escape: false)
        ->assertSee('x-persist="filament-holler.admin"', escape: false)
        ->assertSee('data-peer="muted"', escape: false)
        ->assertSee('data-peer="sharing"', escape: false)
        ->assertSee('data-peer="camera-off"', escape: false)
        ->assertSee('data-self="muted"', escape: false)
        ->assertSee('data-self="camera-off"', escape: false)
        ->assertSee('Your mic is off')
        ->assertSee('data-holler-toggle="mute"', escape: false)
        ->assertSee('aria-live="assertive"', escape: false)
        ->assertSee('role="region" aria-labelledby="fi-holler-window-name"', escape: false)
        ->assertSee('d="m3 3 18 18"', escape: false)
        ->assertSee('Pop-ups are blocked here')
        ->assertSee('the call continues here');

    FilamentHollerPlugin::get()->iceServers(null);
});

it('shows my avatar in my own corner on a voice-only panel, where my stream has no picture', function (): void {
    FilamentHollerPlugin::get()->video(false);

    // selfPlaceholder (holler.js) is true with the camera off or without a video track in my stream, so a voice call
    // shows my avatar rather than an empty dark box; the video under it hides on the same condition.
    $this->actingAs(user('Ion'))->get('/admin')
        ->assertOk()
        ->assertSee('\\u0022video\\u0022:false', escape: false)
        ->assertSee('x-show="selfPlaceholder"', escape: false)
        ->assertSee("'fi-holler-hidden': selfPlaceholder", escape: false);

    $js = file_get_contents(__DIR__.'/../../resources/dist/holler.js');
    expect($js)->toContain('this.localVideo = (stream?.getVideoTracks().length ?? 0) > 0')
        ->toContain('return (this.cameraOff || ! this.localVideo) && ! this.sharing');

    FilamentHollerPlugin::get()->video(true);
});

it('hands the engine the transport policy, and the ice route the ICE servers of the panel', function (): void {
    config()->set('holler.ice_transport_policy', 'relay');
    FilamentHollerPlugin::get()->iceServers(fn (): array => [['urls' => 'turn:turn.example.com', 'username' => 'u', 'credential' => 'fresh']]);

    $this->actingAs($ion = user('Ion'))->get('/admin')
        ->assertOk()
        ->assertSee('\\u0022iceTransportPolicy\\u0022:\\u0022relay\\u0022', escape: false)
        ->assertDontSee('turn:turn.example.com');

    $this->actingAs($ion)->getJson(callsRoute('ice'))
        ->assertOk()
        ->assertExactJson([
            'iceServers' => [['urls' => 'turn:turn.example.com', 'username' => 'u', 'credential' => 'fresh']],
            'iceTransportPolicy' => 'relay',
        ]);

    FilamentHollerPlugin::get()->iceServers(null);
});

it('draws its icons from aliases an app can point elsewhere, and Filament buttons for every control', function (): void {
    $html = $this->actingAs(user('Ion'))->get('/admin')->assertOk()->getContent();

    // The defaults: Heroicons, one per state for the toggles (the mute button no longer shows the same icon both ways).
    expect($html)
        ->toContain('fi-holler-round')
        ->toContain('aria-pressed="true"')
        ->toContain('aria-labelledby="fi-holler-incoming-name"')
        ->toContain('aria-label="Mute"')
        ->toContain('aria-label="Unmute"')
        ->toContain('aria-label="Turn camera on"');

    FilamentIcon::register([
        FilamentHollerIconAlias::HANG_UP => 'heroicon-o-x-circle',
        FilamentHollerIconAlias::UNMUTE => 'heroicon-o-bell-slash',
        FilamentHollerIconAlias::MUTED => 'heroicon-m-no-symbol',
    ]);

    // An icon is known by its SVG path: none of these is on the page before the registration.
    $path = fn (string $icon): string => preg_match('/ d="([^"]+)"/', svg($icon)->contents(), $match) ? $match[1] : $icon;
    $after = $this->get('/admin')->assertOk()->getContent();

    foreach (['heroicon-o-x-circle', 'heroicon-o-bell-slash', 'heroicon-m-no-symbol'] as $icon) {
        expect($html)->not->toContain($path($icon))
            ->and($after)->toContain($path($icon));
    }

    // Hang up is on the chip and in the window.
    expect(substr_count($after, $path('heroicon-o-x-circle')))->toBe(2);

    FilamentIcon::register([
        FilamentHollerIconAlias::HANG_UP => null,
        FilamentHollerIconAlias::UNMUTE => null,
        FilamentHollerIconAlias::MUTED => null,
    ]);
});

it('leaves the widget off the login page', function (): void {
    $this->get('/admin/login')->assertOk()->assertDontSee('packstubHoller(', escape: false);
});

it('hides the call action for the signed-in user and hands the widget the user id', function (): void {
    $ion = user('Ion');
    $ana = user('Ana');

    $this->actingAs($ion);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(CallAction::make()->user($ion)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user($ana)->isVisible())->toBeTrue()
        ->and(CallAction::make()->user($ana)->getAlpineClickHandler())->toBe("\$dispatch('filament-holler-call', { user: '{$ana->getKey()}' })")
        ->and(CallAction::make()->user(null)->isVisible())->toBeFalse();
});

it('shows a Call button on every other user in a table and hands it the user id', function (): void {
    $ion = user('Ion');
    $ana = user('Ana');

    $this->actingAs($ion);

    livewire(ListUsers::class)
        ->assertTableActionVisible('call', $ana)
        ->assertTableActionHidden('call', $ion)
        ->assertSee("\$dispatch('filament-holler-call', { user: '{$ana->getKey()}' })", escape: false);
});

it('keeps one widget per panel across SPA page loads, with that panel\'s routes', function (): void {
    $ion = tap(user('Ion'))->markEmailAsVerified();

    $this->actingAs($ion)->get('/verified')
        ->assertOk()
        ->assertSee('x-persist="filament-holler.verified"', escape: false)
        ->assertSee(str_replace('/', '\\\\\\/', url('/verified/filament-holler/calls/poll')), escape: false);
});

it('draws the device settings, the notifications link and the weak-connection badge, and lets a panel turn them off', function (): void {
    $this->actingAs(user('Ion'))->get('/admin')
        ->assertOk()
        ->assertSee('\\u0022devices\\u0022:true', escape: false)
        ->assertSee('\\u0022notifications\\u0022:true', escape: false)
        ->assertSee('data-holler-settings', escape: false)
        ->assertSee('aria-controls="fi-holler-settings"', escape: false)
        ->assertSee('data-holler-device="microphone"', escape: false)
        ->assertSee('data-holler-device="camera"', escape: false)
        ->assertSee('data-holler-device="speaker"', escape: false)
        ->assertSee('System default')
        ->assertSee('data-holler-notify', escape: false)
        ->assertSee('Notify me about calls when this tab is in the background')
        ->assertSee('data-peer="quality"', escape: false)
        ->assertSee('Weak connection');

    FilamentHollerPlugin::get()->deviceSettings(false)->notifications(fn (): bool => false);

    expect(FilamentHollerPlugin::get()->hasDeviceSettings())->toBeFalse()
        ->and(FilamentHollerPlugin::get()->hasNotifications())->toBeFalse();

    $this->get('/admin')
        ->assertSee('\\u0022devices\\u0022:false', escape: false)
        ->assertSee('\\u0022notifications\\u0022:false', escape: false);

    FilamentHollerPlugin::get()->deviceSettings()->notifications();
});

it('ships the same labels in every language', function (): void {
    $english = require __DIR__.'/../../resources/lang/en/calls.php';

    foreach (glob(__DIR__.'/../../resources/lang/*/calls.php') as $file) {
        $labels = require $file;

        expect(array_keys($labels))->toBe(array_keys($english), $file);

        foreach ($english as $key => $text) {
            preg_match_all('/:[a-z]+/', $text, $placeholders);
            expect($labels[$key])->toContain(...$placeholders[0]);
        }
    }
});

it('signs TURN credentials that expire for a server with a secret, per panel', function (): void {
    $this->freezeSecond();
    FilamentHollerPlugin::get()->iceServers(fn (): array => [['urls' => 'turn:turn.example.com:3478, turns:turn.example.com:443?transport=tcp', 'secret' => 'shared', 'ttl' => 600]]);

    $ion = user('Ion');
    $username = (now()->getTimestamp() + 600).':'.$ion->getKey();

    $this->actingAs($ion)->getJson('/admin/filament-holler/calls/ice-servers')
        ->assertOk()
        ->assertExactJson([
            'iceServers' => [[
                'urls' => ['turn:turn.example.com:3478', 'turns:turn.example.com:443?transport=tcp'],
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, 'shared', true)),
            ]],
            'iceTransportPolicy' => 'all',
        ]);

    FilamentHollerPlugin::get()->iceServers(null);
});
