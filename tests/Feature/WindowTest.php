<?php

use Filament\Facades\Filament;
use Packstub\Calls\FilamentCallsPlugin;
use Packstub\Calls\Models\Call;

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
        ->assertSee('\\u0022autoStart\\u0022:{\\u0022call\\u0022:'.$call->id.'}', escape: false);

    $this->actingAs($this->ion)->get(callsRoute('window', ['callee' => $this->ana->getKey()]))
        ->assertOk()
        ->assertSee('\\u0022autoStart\\u0022:{\\u0022callee\\u0022:\\u0022'.$this->ana->getKey().'\\u0022}', escape: false);

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
