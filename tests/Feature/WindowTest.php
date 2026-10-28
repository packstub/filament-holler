<?php

use Filament\Facades\Filament;
use Packstub\Calls\Models\Call;
use Packstub\FilamentCalls\FilamentCallsPlugin;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('serves the pop-out page for a call of mine, or for someone to ring, and refuses other people\'s calls', function (): void {
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey(), 'tab' => 'win-a'])->assertCreated();
    $call = Call::query()->latest('id')->firstOrFail();

    $this->actingAs($this->ana)->get(callsRoute('window', ['call' => $call->id]))
        ->assertOk()
        ->assertSee('fi-calls-popup', escape: false)
        ->assertSee('\\u0022popup\\u0022:true', escape: false)
        // The page does not tell the widget to answer: it reads ?call= itself and answers only
        // with the one-time token the opening page left in localStorage.
        ->assertDontSee('autoStart');

    $this->actingAs($this->ion)->get(callsRoute('window', ['callee' => $this->ana->getKey()]))
        ->assertOk()
        ->assertDontSee('autoStart');

    $this->actingAs(user('Mihai'))->get(callsRoute('window', ['call' => $call->id]))->assertForbidden();
});

it('pops calls out unless the panel runs in SPA mode, and lets a panel decide otherwise', function (): void {
    $plugin = FilamentCallsPlugin::get();
    $panel = Filament::getPanel('admin');

    expect($plugin->isPopOut())->toBeTrue();

    $panel->spa();
    expect($plugin->isPopOut())->toBeFalse();

    $plugin->popOut(true);
    expect($plugin->isPopOut())->toBeTrue();

    $plugin->popOut(fn (): bool => false);
    expect($plugin->isPopOut())->toBeFalse();

    $plugin->popOut(null);
    $panel->spa(false);
    expect($plugin->isPopOut())->toBeTrue();

    $this->actingAs($this->ion)->get('/admin')->assertSee('\\u0022popOut\\u0022:true', escape: false)->assertSee('\\u0022popup\\u0022:false', escape: false);
});
