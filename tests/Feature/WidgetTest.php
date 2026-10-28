<?php

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentIcon;
use Packstub\FilamentCalls\Actions\CallAction;
use Packstub\FilamentCalls\FilamentCallsPlugin;
use Packstub\FilamentCalls\Tests\Fixtures\ListUsers;
use Packstub\FilamentCalls\View\FilamentCallsIconAlias;

use function Pest\Livewire\livewire;

it('renders the call widget on every panel page with the panel routes, and no ICE servers in the page', function (): void {
    FilamentCallsPlugin::get()->iceServers(fn () => throw new RuntimeException('ICE servers are asked per call, not per page.'));

    // The config travels through @js (json_encode inside a JS string), which escapes each slash to \\\/.
    $js = fn (string $url): string => str_replace('\\', '\\\\\\', trim(json_encode($url), '"'));

    $this->actingAs(user('Ion'))->get('/admin')
        ->assertOk()
        ->assertSee('packstubCalls(', escape: false)
        ->assertDontSee('iceServers')
        ->assertSee($js(url('/admin/filament-calls/calls/ice-servers')), escape: false)
        ->assertSee('\\u0022iceTransportPolicy\\u0022:\\u0022all\\u0022', escape: false)
        ->assertDontSee('autoStart')
        ->assertSee('\\u0022kind\\u0022:\\u0022call\\u0022', escape: false)
        ->assertSee($js(url('/admin/filament-calls/calls/poll')), escape: false)
        ->assertSee($js(url('/admin/filament-calls/calls/__ID__/answer')), escape: false)
        ->assertSee($js(url('/admin/filament-calls/calls/__ID__/invite')), escape: false)
        ->assertSee('\\u0022maxParticipants\\u0022:4', escape: false)
        ->assertSee('data-peer-id', escape: false)
        ->assertSee('x-persist="filament-calls"', escape: false)
        ->assertSee('data-peer="muted"', escape: false)
        ->assertSee('data-peer="sharing"', escape: false)
        ->assertSee('data-peer="camera-off"', escape: false)
        ->assertSee('Pop-ups are blocked here')
        ->assertSee('the call continues here');

    FilamentCallsPlugin::get()->iceServers(null);
});

it('hands the engine the transport policy, and the ice route the ICE servers of the panel', function (): void {
    config()->set('packstub-calls.ice_transport_policy', 'relay');
    FilamentCallsPlugin::get()->iceServers(fn (): array => [['urls' => 'turn:turn.example.com', 'username' => 'u', 'credential' => 'fresh']]);

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

    FilamentCallsPlugin::get()->iceServers(null);
});

it('draws its icons from aliases an app can point elsewhere, and Filament buttons for every control', function (): void {
    $html = $this->actingAs(user('Ion'))->get('/admin')->assertOk()->getContent();

    // The defaults: Heroicons, one per state for the toggles (the mute button no longer shows the same icon both ways).
    expect($html)
        ->toContain('fi-calls-round')
        ->toContain('aria-pressed="true"')
        ->toContain('aria-labelledby="fi-calls-incoming-name"')
        ->toContain('aria-label="Mute"')
        ->toContain('aria-label="Unmute"')
        ->toContain('aria-label="Turn camera on"');

    FilamentIcon::register([
        FilamentCallsIconAlias::HANG_UP => 'heroicon-o-x-circle',
        FilamentCallsIconAlias::UNMUTE => 'heroicon-o-bell-slash',
        FilamentCallsIconAlias::MUTED => 'heroicon-m-no-symbol',
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
        FilamentCallsIconAlias::HANG_UP => null,
        FilamentCallsIconAlias::UNMUTE => null,
        FilamentCallsIconAlias::MUTED => null,
    ]);
});

it('leaves the widget off the login page', function (): void {
    $this->get('/admin/login')->assertOk()->assertDontSee('packstubCalls(', escape: false);
});

it('hides the call action for the signed-in user and hands the widget the user id', function (): void {
    $ion = user('Ion');
    $ana = user('Ana');

    $this->actingAs($ion);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(CallAction::make()->user($ion)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user($ana)->isVisible())->toBeTrue()
        ->and(CallAction::make()->user($ana)->getAlpineClickHandler())->toBe("\$dispatch('filament-calls-call', { user: '{$ana->getKey()}' })")
        ->and(CallAction::make()->user(null)->isVisible())->toBeFalse();
});

it('shows a Call button on every other user in a table and hands it the user id', function (): void {
    $ion = user('Ion');
    $ana = user('Ana');

    $this->actingAs($ion);

    livewire(ListUsers::class)
        ->assertTableActionVisible('call', $ana)
        ->assertTableActionHidden('call', $ion)
        ->assertSee("\$dispatch('filament-calls-call', { user: '{$ana->getKey()}' })", escape: false);
});
