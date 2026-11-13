<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Packstub\FilamentHoller\PanelContext;
use Packstub\Holler\AppContext;
use Packstub\Holler\Contracts\CallContext;
use Packstub\Holler\Facades\Holler;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('answers like a plain app outside a panel: the core\'s guard, not the default panel\'s', function (): void {
    Filament::setCurrentPanel(null);
    config()->set('holler.guard', 'web');

    expect(app(PanelContext::class)->plugin())->toBeNull()
        ->and(Holler::context()->scope())->toBe('web')
        ->and(Holler::currentUserId())->toBeNull();
});

it('binds the panel context over the core\'s app context', function (): void {
    expect(app(CallContext::class))->toBeInstanceOf(PanelContext::class)
        ->and(Holler::context())->toBe(app(PanelContext::class))
        ->and(app(PanelContext::class)->plugin())->toBe(FilamentHollerPlugin::get());
});

it('names people the way the panel does and finds them through the panel guard', function (): void {
    $this->actingAs($this->ion);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(Holler::currentUserId())->toBe((string) $this->ion->getKey())
        ->and(Holler::findUser((string) $this->ana->getKey())?->is($this->ana))->toBeTrue()
        ->and(Holler::findUser('999'))->toBeNull()
        ->and(Holler::who($this->ana))->toBe([
            'id' => (string) $this->ana->getKey(),
            'name' => Filament::getUserName($this->ana),
            'avatar' => Filament::getUserAvatarUrl($this->ana),
        ]);
});

it('never asks the database for an id that cannot be a key of the panel\'s users', function (): void {
    $this->actingAs($this->ion);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $context = app(PanelContext::class);

    DB::enableQueryLog();

    expect($context->findUser('abc'))->toBeNull()
        ->and($context->findUser('1 OR 1=1'))->toBeNull()
        ->and($context->findUsers(['abc', '-1', '']))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);

    expect(collect($context->findUsers(['abc', (string) $this->ana->getKey()]))->map->getKey()->all())->toBe([$this->ana->getKey()])
        ->and($context->findUser((string) $this->ana->getKey())?->is($this->ana))->toBeTrue();
});

it('answers who may call, the ICE servers and video from the plugin, with the config behind it', function (): void {
    $plugin = FilamentHollerPlugin::get();
    $context = Holler::context();

    expect($context->canCall($this->ion, $this->ana))->toBeTrue()
        ->and($context->canCall($this->ion, $this->ion))->toBeFalse()
        ->and($context->iceServers())->toBe(config('holler.ice_servers'))
        ->and($context->hasVideo())->toBeTrue();

    expect($context->maxParticipants())->toBe(4);

    $plugin->canCall(fn (): bool => false)->iceServers([['urls' => 'turn:t']])->video(false)->maxParticipants(fn (): int => 3);

    expect($context->canCall($this->ion, $this->ana))->toBeFalse()
        ->and($context->iceServers())->toBe([['urls' => 'turn:t']])
        ->and($context->hasVideo())->toBeFalse()
        ->and($context->maxParticipants())->toBe(3);

    $plugin->maxParticipants(1);
    expect($context->maxParticipants())->toBe(2);

    $plugin->canCall(null)->iceServers(null)->video(true)->maxParticipants(null);
    config()->set('holler.video', false);

    expect($context->hasVideo())->toBeFalse();

    // The core's own closures are not consulted while a panel with the plugin is current.
    app(AppContext::class)->canCallUsing(fn (): bool => false);
    expect($context->canCall($this->ion, $this->ana))->toBeTrue();
    app(AppContext::class)->canCallUsing(null);
});

it('finds the panel\'s users for Add someone, or whoever the plugin\'s own search finds, and holds a call through the panel routes', function (): void {
    $mihai = user('Mihai');
    $this->actingAs($this->ion);

    $names = fn (string $query): array => collect(Holler::people($this->ion, $query))->pluck('name')->all();

    expect($names(''))->toBe(['Ana', 'Mihai'])
        ->and($names('mih'))->toBe(['Mihai'])
        ->and($names('ana@'))->toBe([]);

    $this->getJson('/admin/filament-holler/calls/people?q=an')->assertOk()->assertJsonPath('people.0.name', 'Ana')->assertJsonCount(1, 'people');

    FilamentHollerPlugin::get()->searchUsersUsing(fn ($caller, string $query, int $limit) => [$mihai]);

    expect($names('ana'))->toBe(['Mihai']);

    FilamentHollerPlugin::get()->searchUsersUsing(null);

    $id = $this->postJson('/admin/filament-holler/calls', ['callee' => $this->ana->getKey(), 'tab' => 'ion-1'])->assertCreated()->json('id');
    $this->actingAs($this->ana)->postJson("/admin/filament-holler/calls/{$id}/answer", ['tab' => 'ana-1'])->assertOk();
    $this->actingAs($this->ion)->postJson("/admin/filament-holler/calls/{$id}/hold")->assertOk()->assertJsonPath('on_hold', true);
    $this->getJson('/admin/filament-holler/calls/poll')->assertJsonPath('on_hold.id', $id)->assertJsonPath('call', null);
    $this->postJson("/admin/filament-holler/calls/{$id}/resume", ['tab' => 'ion-1'])->assertOk()->assertJsonPath('joined', true);
});
