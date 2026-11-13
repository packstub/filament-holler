<?php

use Illuminate\Support\Facades\Event;
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Packstub\FilamentHoller\Tests\Fixtures\User;
use Packstub\Holler\Events\CallEnded;
use Packstub\Holler\Events\CallStarted;
use Packstub\Holler\Models\Call;
use Packstub\Holler\Models\CallSignal;

/*
 * The signaling logic itself is tested in packstub/holler. These tests cover what
 * the panel adds: the routes under the panel's prefix and auth, the plugin's
 * canCall(), and names from the panel.
 */

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
});

it('registers the core routes under the panel and runs a call through them', function (): void {
    Event::fake([CallStarted::class, CallEnded::class]);

    expect(callsRoute('poll'))->toBe(url('/admin/filament-holler/calls/poll'))
        ->and(callsRoute('store'))->toBe(url('/admin/filament-holler/calls'))
        ->and(callsRoute('signal', ['call' => 7]))->toBe(url('/admin/filament-holler/calls/7/signals'))
        ->and(callsRoute('invite', ['call' => 7]))->toBe(url('/admin/filament-holler/calls/7/invite'))
        ->and(callsRoute('window'))->toBe(url('/admin/filament-holler/window'));

    $this->actingAs($this->ion)
        ->postJson(callsRoute('store'), ['callee' => $this->ana->getKey(), 'tab' => 'win-a'])
        ->assertCreated()
        ->assertJsonPath('status', 'ringing')
        ->assertJsonPath('role', 'caller')
        ->assertJsonPath('peer.name', 'Ana');

    $call = Call::query()->latest('id')->firstOrFail();
    Event::assertDispatched(CallStarted::class);

    $this->actingAs($this->ana)->getJson(callsRoute('poll'))->assertJsonPath('incoming.id', $call->id)->assertJsonPath('incoming.peer.name', 'Ion');
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'offer', 'payload' => ['sdp' => 'v=0']])->assertCreated();
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-1'])->assertOk()->assertJsonPath('status', 'active');
    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ana-1', 'hold' => 1]))->assertJsonPath('signals.0.type', 'offer');
    $this->actingAs($this->ana)->post(callsRoute('leave', ['call' => $call]), ['tab' => 'ana-1'])->assertNoContent();
    $this->actingAs($this->ion)->postJson(callsRoute('hangup', ['call' => $call]))->assertOk()->assertJsonPath('end_reason', 'hung_up');

    Event::assertDispatched(CallEnded::class);
    expect(CallSignal::query()->count())->toBe(0);
});

it('refuses a call the panel says no to, on the server', function (): void {
    FilamentHollerPlugin::get()->canCall(fn (User $caller, User $callee): bool => $callee->name !== 'Ana');

    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This person cannot be called.');
    $this->actingAs($this->ana)->postJson(callsRoute('store'), ['callee' => $this->ion->getKey()])->assertCreated();

    FilamentHollerPlugin::get()->canCall(null);

    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ion->getKey()])->assertStatus(422);
});

it('sits behind the panel\'s auth and keeps bystanders out of a call', function (): void {
    $this->getJson(callsRoute('poll'))->assertUnauthorized();
    $this->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])->assertUnauthorized();

    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])->assertCreated();
    $call = Call::query()->latest('id')->firstOrFail();

    $this->actingAs(user('Mihai'))->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'ice', 'payload' => ['x' => 1]])->assertForbidden();
});

it('keeps a session description byte for byte through the panel route, trailing CRLF included', function (): void {
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey()])->assertCreated();
    $call = Call::query()->latest('id')->firstOrFail();
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]));

    $sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\na=ssrc:1 msid:a b\r\n";
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'offer', 'payload' => ['sdp' => $sdp]])->assertCreated();

    expect($this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'tab', 'hold' => 1]))->json('signals.0.payload.sdp'))->toBe($sdp);
});

it('adds a third person through the panel route, within the plugin\'s limit', function (): void {
    $mihai = user('Mihai');
    $this->actingAs($this->ion)->postJson(callsRoute('store'), ['callee' => $this->ana->getKey(), 'tab' => 'win-a'])->assertCreated();
    $call = Call::query()->latest('id')->firstOrFail();
    $this->actingAs($this->ana)->postJson(callsRoute('answer', ['call' => $call]), ['tab' => 'ana-1'])->assertOk();

    $this->actingAs($this->ana)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => $mihai->getKey()])
        ->assertOk()
        ->assertJsonPath('peers.1.name', 'Mihai')
        ->assertJsonPath('peers.1.seat', 2)
        ->assertJsonPath('peers.1.status', 'ringing');
    $this->actingAs($mihai)->getJson(callsRoute('poll'))->assertJsonPath('incoming.id', $call->id)->assertJsonCount(2, 'incoming.peers');

    // The plugin's limit is the server's.
    FilamentHollerPlugin::get()->maxParticipants(3);
    $this->actingAs($this->ion)->postJson(callsRoute('invite', ['call' => $call]), ['callee' => user('Dana')->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A call takes up to 3 people.');
    FilamentHollerPlugin::get()->maxParticipants(null);

    // Signals can be addressed to one person and say who sent them.
    $this->actingAs($this->ion)->postJson(callsRoute('signal', ['call' => $call]), ['type' => 'offer', 'payload' => ['sdp' => 'v=0'], 'to' => (string) $this->ana->getKey()])->assertCreated();
    $this->actingAs($this->ana)->getJson(callsRoute('poll', ['call' => $call->id, 'tab' => 'ana-1', 'hold' => 1]))
        ->assertJsonPath('signals.0.from', (string) $this->ion->getKey());
});
