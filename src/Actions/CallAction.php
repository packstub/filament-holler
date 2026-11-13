<?php

namespace Packstub\FilamentHoller\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Js;
use Packstub\FilamentHoller\FilamentHollerPlugin;

/**
 * A button that rings a user. Client-side only: it hands the user id (and the
 * name, for the question the widget asks when pressed during a call) to the
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

        $this->label(fn (): string => __('filament-holler::calls.call'));
        $this->icon(Heroicon::OutlinedPhone);
        $this->color('primary');

        $this->alpineClickHandler(fn (CallAction $action): string => '$dispatch(\'filament-holler-call\', { user: '.Js::from((string) $action->getUser()?->getAuthIdentifier()).', name: '.Js::from($action->getUser() ? Filament::getUserName($action->getUser()) : null).' })');
    }

    /**
     * Shown only where the panel runs the plugin, for another user the signed-in one may call;
     * an app's own `visible()` or `hidden()` narrows that further and never widens it.
     */
    protected function resolveIsHiddenInGroup(): bool
    {
        return parent::resolveIsHiddenInGroup() || ! $this->canCallUser();
    }

    /** Whether the signed-in user may call this action's user (nobody calls themselves). */
    public function canCallUser(): bool
    {
        $user = $this->getUser();
        $me = Filament::auth()->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if (! $user || ! $me || ! $panel?->hasPlugin('filament-holler')) {
            return false;
        }

        /** @var FilamentHollerPlugin $plugin */
        $plugin = $panel->getPlugin('filament-holler');

        return $plugin->isCallAllowed($me, $user);
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
