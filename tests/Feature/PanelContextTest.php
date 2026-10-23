<?php

use Filament\Facades\Filament;
use Packstub\Calls\AppContext;
use Packstub\Calls\Contracts\CallContext;
use Packstub\Calls\Facades\Calls;
use Packstub\Calls\FilamentCallsPlugin;
use Packstub\Calls\PanelContext;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('binds the panel context over the core\'s app context', function (): void {
    expect(app(CallContext::class))->toBeInstanceOf(PanelContext::class)
        ->and(Calls::context())->toBe(app(PanelContext::class))
        ->and(app(PanelContext::class)->plugin())->toBe(FilamentCallsPlugin::get());
});

it('names people the way the panel does and finds them through the panel guard', function (): void {
    $this->actingAs($this->ion);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(Calls::currentUserId())->toBe((string) $this->ion->getKey())
        ->and(Calls::findUser((string) $this->ana->getKey())?->is($this->ana))->toBeTrue()
        ->and(Calls::findUser('999'))->toBeNull()
        ->and(Calls::who($this->ana))->toBe([
            'id' => (string) $this->ana->getKey(),
            'name' => Filament::getUserName($this->ana),
            'avatar' => Filament::getUserAvatarUrl($this->ana),
        ]);
});

it('answers who may call, the ICE servers and video from the plugin, with the config behind it', function (): void {
    $plugin = FilamentCallsPlugin::get();
    $context = Calls::context();

    expect($context->canCall($this->ion, $this->ana))->toBeTrue()
        ->and($context->canCall($this->ion, $this->ion))->toBeFalse()
        ->and($context->iceServers())->toBe(config('packstub-calls.ice_servers'))
        ->and($context->hasVideo())->toBeTrue();

    $plugin->canCall(fn (): bool => false)->iceServers([['urls' => 'turn:t']])->video(false);

    expect($context->canCall($this->ion, $this->ana))->toBeFalse()
        ->and($context->iceServers())->toBe([['urls' => 'turn:t']])
        ->and($context->hasVideo())->toBeFalse();

    $plugin->canCall(null)->iceServers(null)->video(true);
    config()->set('packstub-calls.video', false);

    expect($context->hasVideo())->toBeFalse();

    // The core's own closures are not consulted while a panel with the plugin is current.
    app(AppContext::class)->canCallUsing(fn (): bool => false);
    expect($context->canCall($this->ion, $this->ana))->toBeTrue();
    app(AppContext::class)->canCallUsing(null);
});
