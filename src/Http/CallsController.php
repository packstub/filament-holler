<?php

namespace Packstub\Calls\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Packstub\Calls\Calls;
use Packstub\Calls\Events\CallAnswered;
use Packstub\Calls\Events\CallEnded;
use Packstub\Calls\Events\CallStarted;
use Packstub\Calls\FilamentCallsPlugin;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallSignal;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The signaling backend: a few JSON endpoints behind the panel's auth. The
 * browsers exchange offer, answer and ICE candidates through here and then
 * talk to each other directly; no media ever comes through this controller.
 */
class CallsController
{
    /** Ring someone. Any call the caller still has open is ended first. */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['callee' => ['required'], 'tab' => ['nullable', 'string', 'max:32']]);
        $calleeId = $request->input('callee');

        if (! is_scalar($calleeId) || strlen((string) $calleeId) > 64) {
            throw new HttpException(422, __('filament-calls::calls.cannot_call'));
        }

        $caller = $request->user();
        $callee = Calls::findUser((string) $calleeId);

        if (! $callee || ! FilamentCallsPlugin::get()->isCallAllowed($caller, $callee)) {
            throw new HttpException(422, __('filament-calls::calls.cannot_call'));
        }

        $me = Calls::currentUserId();

        Call::query()->where('status', '!=', Call::ENDED)
            ->where(fn ($query) => $query->where('caller_id', $me)->orWhere('callee_id', $me))
            ->each(fn (Call $open) => $this->end($open, 'cancelled'));

        if (Call::query()->where('status', '!=', Call::ENDED)->where(fn ($query) => $query->where('caller_id', (string) $calleeId)->orWhere('callee_id', (string) $calleeId))->exists()) {
            throw new HttpException(409, __('filament-calls::calls.busy'));
        }

        $tab = $request->input('tab');

        $call = Call::query()->create([
            'caller_id' => $me,
            'callee_id' => (string) $callee->getAuthIdentifier(),
            'status' => Call::RINGING,
            'caller_tab' => $tab,
            'caller_seen_at' => $tab ? now() : null,
        ]);

        CallStarted::dispatch($call);

        return response()->json($this->present($call, $me, $tab), 201);
    }

    public function answer(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        if ($call->callee_id !== $me || ! $call->isRinging()) {
            throw new HttpException(409, __('filament-calls::calls.not_ringing'));
        }

        $request->validate(['tab' => ['nullable', 'string', 'max:32']]);
        $tab = $request->input('tab');

        $call->forceFill([
            'status' => Call::ACTIVE,
            'answered_at' => now(),
            'callee_tab' => $tab,
            'callee_seen_at' => $tab ? now() : null,
        ])->save();

        CallAnswered::dispatch($call);

        return response()->json($this->present($call, $me, $tab));
    }

    public function decline(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        $this->end($call, $call->callee_id === $me ? 'declined' : 'cancelled');

        return response()->json($this->present($call, $me));
    }

    public function hangup(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        $this->end($call, $call->isRinging() ? ($call->caller_id === $me ? 'cancelled' : 'declined') : 'hung_up');

        return response()->json($this->present($call, $me));
    }

    /**
     * The window is going away (a beacon on unload): its side is free for the
     * next page to claim at once, and the call lives on until the reconnect
     * timeout if nobody does.
     */
    public function leave(Request $request, Call $call): Response
    {
        $me = $this->participant($call);

        $call->release($me, (string) $request->input('tab'));

        return response()->noContent();
    }

    /** Queue one signaling message for the other side. */
    public function signal(Request $request, Call $call): JsonResponse
    {
        $me = $this->participant($call);

        if ($call->isEnded()) {
            throw new HttpException(410, __('filament-calls::calls.ended'));
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(CallSignal::TYPES)],
            'payload' => ['required', 'array'],
        ]);

        $signal = $call->signals()->create([
            'to_user_id' => $call->peerOf($me),
            'type' => $data['type'],
            'payload' => $data['payload'],
            'created_at' => now(),
        ]);

        return response()->json(['id' => $signal->id], 201);
    }

    /**
     * The one request every window repeats: an incoming call if someone is
     * ringing me, the call I am in (held by this window from then on when it
     * asks to `hold` it, which is also its heartbeat), and the signals sent to
     * me since `after`.
     */
    public function poll(Request $request): JsonResponse
    {
        $me = Calls::currentUserId();
        $after = (int) $request->query('after', 0);
        $currentId = (int) $request->query('call', 0);
        $tab = substr((string) $request->query('tab', ''), 0, 32) ?: null;
        // Only a window that runs the connection takes the side; a tab showing the chip just watches.
        $hold = $request->boolean('hold');

        $mine = fn ($query) => $query->where('caller_id', $me)->orWhere('callee_id', $me);

        Call::query()->where('status', Call::RINGING)
            ->where('created_at', '<', now()->subSeconds(config('filament-calls.ring_timeout', 45)))
            ->where($mine)
            ->each(fn (Call $stale) => $this->end($stale, 'missed'));

        // A side nobody has polled for since the reconnect timeout is gone for good.
        $deadline = now()->subSeconds(config('filament-calls.reconnect_timeout', 15));
        Call::query()->where('status', Call::ACTIVE)->where($mine)
            ->where(fn ($query) => $query->where('caller_seen_at', '<', $deadline)->orWhere('callee_seen_at', '<', $deadline))
            ->each(fn (Call $lost) => $this->end($lost, 'dropped'));

        $open = Call::query()->where('status', '!=', Call::ENDED)->where($mine)->latest('id')->get();

        // The call this window is in (it names it, so it also learns when that call ended), or else
        // any open call the server still has me in, for a page that just loaded.
        $current = $currentId
            ? Call::query()->find($currentId)
            : $open->first(fn (Call $call) => $call->isActive() || $call->caller_id === $me);

        if ($current && ! $current->involves($me)) {
            $current = null;
        }

        $held = false;
        $reclaimed = false;

        if ($current && $currentId && $tab && $hold && ! $current->isEnded()) {
            $held = $current->claim($me, $tab, $reclaimed);

            if ($reclaimed) {
                // A fresh page starts after the signals meant for the old connection (its own
                // session check covers the rest); they stay in case the old window is still alive.
                $after = max($after, (int) CallSignal::query()->max('id'));
            }
        }

        $incoming = $open->first(fn (Call $call) => $call->isRinging() && $call->callee_id === $me && ! $current?->is($call));

        // Signals only flow to the window that holds the side: a callee gets the offer once they answer.
        $signals = $held
            ? CallSignal::query()->where('to_user_id', $me)->where('call_id', $current->getKey())->where('id', '>', $after)->orderBy('id')->limit(100)->get()
            : collect();

        return response()->json([
            'call' => $current ? $this->present($current, $me, $tab) : null,
            'incoming' => $incoming ? $this->present($incoming, $me, $tab) : null,
            'signals' => $signals->map(fn (CallSignal $signal) => [
                'id' => $signal->id,
                'call' => $signal->call_id,
                'type' => $signal->type,
                'payload' => $signal->payload,
            ])->all(),
            'cursor' => $signals->max('id') ?? $after,
        ]);
    }

    protected function participant(Call $call): string
    {
        $me = Calls::currentUserId();

        abort_unless($call->involves($me), 403);

        return $me;
    }

    protected function end(Call $call, string $reason): void
    {
        if ($call->isEnded()) {
            return;
        }

        $call->end($reason);

        CallEnded::dispatch($call);
    }

    /** @return array<string, mixed> */
    protected function present(Call $call, string $me, ?string $tab = null): array
    {
        $peer = Calls::findUser($call->peerOf($me));
        $side = $call->sideOf($me);
        $peerSide = $side === 'caller' ? 'callee' : 'caller';

        return [
            'id' => $call->id,
            'status' => $call->status,
            'end_reason' => $call->end_reason,
            'role' => $side,
            'peer' => $peer ? Calls::who($peer) : ['id' => $call->peerOf($me), 'name' => __('filament-calls::calls.someone'), 'avatar' => null],
            'answered_at' => $call->answered_at?->toIso8601String(),
            // Another window of mine holds my side.
            'held' => $call->isHeld($side) && $call->{"{$side}_tab"} !== $tab,
            'peer_held' => $call->isHeld($peerSide),
            'peer_seen_ago' => $call->{"{$peerSide}_seen_at"} ? max(0, (int) $call->{"{$peerSide}_seen_at"}->diffInSeconds(now())) : null,
        ];
    }
}
