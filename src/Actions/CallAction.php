<?php

namespace Packstub\FilamentCalls\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Js;
use Packstub\FilamentCalls\FilamentCallsPlugin;

/**
 * A button that rings a user. Client-side only: it hands the user id to the
 * call widget, which asks the server to start the call (and the server is the
 * one that decides whether the call is allowed).
 */
class CallAction extends Action
{
    protected Authenticatable|Closure|null $user = null;

    public static function getDefaultName(): ?string
    {
        return 'call';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('filament-calls::calls.call'));
        $this->icon(Heroicon::OutlinedPhone);
        $this->color('primary');

        // Closures take the injected $action, not $this: a table clones the action per row,
        // and a closure bound to the original would never see the row's record.
        // Nobody calls themselves, and only Authenticatable records can be called.
        // Only where the panel runs the plugin, and for someone the signed-in user may call.
        $this->visible(function (CallAction $action): bool {
            $user = $action->getUser();
            $me = Filament::auth()->user();
            $panel = Filament::getCurrentOrDefaultPanel();

            if (! $user || ! $me || ! $panel?->hasPlugin('filament-calls')) {
                return false;
            }

            /** @var FilamentCallsPlugin $plugin */
            $plugin = $panel->getPlugin('filament-calls');

            return $plugin->isCallAllowed($me, $user);
        });

        $this->alpineClickHandler(fn (CallAction $action): string => '$dispatch(\'filament-calls-call\', { user: '.Js::from((string) $action->getUser()?->getAuthIdentifier()).' })');
    }

    /** The user to call; defaults to the action's record. */
    public function user(Authenticatable|Closure|null $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?Authenticatable
    {
        $user = $this->evaluate($this->user) ?? $this->getRecord();

        return $user instanceof Authenticatable ? $user : null;
    }
}
