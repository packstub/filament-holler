<?php

use Filament\Facades\Filament;
use Packstub\Calls\Actions\CallAction;
use Packstub\Calls\FilamentCallsPlugin;
use Packstub\Calls\Tests\Fixtures\ListUsers;

use function Pest\Livewire\livewire;

it('renders the call widget on every panel page with the panel routes and ICE servers', function (): void {
    FilamentCallsPlugin::get()->iceServers([['urls' => 'turn:turn.example.com', 'username' => 'u', 'credential' => 'c']]);

    // The config travels through @js (json_encode inside a JS string), which escapes each slash to \\\/.
    $js = fn (string $url): string => str_replace('\\', '\\\\\\', trim(json_encode($url), '"'));

    $this->actingAs(user('Ion'))->get('/admin')
        ->assertOk()
        ->assertSee('packstubCalls(', escape: false)
        ->assertSee('turn:turn.example.com')
        ->assertSee($js(url('/admin/filament-calls/poll')), escape: false)
        ->assertSee($js(url('/admin/filament-calls/calls/__ID__/answer')), escape: false)
        ->assertSee('x-persist="filament-calls"', escape: false);

    FilamentCallsPlugin::get()->iceServers(null);
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
