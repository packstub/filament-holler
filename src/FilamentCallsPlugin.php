<?php

namespace Packstub\FilamentCalls;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Packstub\Calls\Facades\Calls;
use Packstub\FilamentCalls\Http\WindowController;

class FilamentCallsPlugin implements Plugin
{
    use EvaluatesClosures;

    protected ?Closure $canCall = null;

    /** @var array<int, array<string, mixed>> | Closure | null */
    protected array|Closure|null $iceServers = null;

    protected bool|Closure $video = true;

    protected bool|Closure|null $popOut = null;

    protected int|Closure|null $maxParticipants = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-calls';
    }

    /**
     * Decide who may call whom. Receives the caller and the callee. Whatever it
     * says, nobody calls themselves or someone who cannot open the panel; without
     * it, two users of a panel with tenancy must share a tenant.
     */
    public function canCall(?Closure $callback): static
    {
        $this->canCall = $callback;

        return $this;
    }

    /** @param  array<int, array<string, mixed>> | Closure | null  $servers */
    public function iceServers(array|Closure|null $servers): static
    {
        $this->iceServers = $servers;

        return $this;
    }

    /** Start calls with the camera on (default) or as voice calls. */
    public function video(bool|Closure $condition = true): static
    {
        $this->video = $condition;

        return $this;
    }

    /**
     * Run calls in their own small browser window, so the main tab can go
     * anywhere, reload or close. Default: on unless the panel runs in SPA mode,
     * where a call already follows the person from page to page; SPA panels
     * can still opt in, and other panels can opt out (calls then reconnect
     * after every full page load).
     */
    public function popOut(bool|Closure|null $condition = true): static
    {
        $this->popOut = $condition;

        return $this;
    }

    /**
     * People on one call, the caller included (default: the core's `max_participants`,
     * 4). The browsers connect to each other directly, so each uploads its media once
     * per other participant; four is where that stops being comfortable.
     */
    public function maxParticipants(int|Closure|null $max): static
    {
        $this->maxParticipants = $max;

        return $this;
    }

    public function getMaxParticipants(): int
    {
        return max(2, (int) ($this->evaluate($this->maxParticipants) ?? config('packstub-calls.max_participants', 4)));
    }

    public function isPopOut(): bool
    {
        // An SPA panel keeps the call across pages by itself; elsewhere the core's `pop_out` (CALLS_POP_OUT) decides.
        return $this->evaluate($this->popOut)
            ?? (! Filament::getCurrentOrDefaultPanel()->hasSpaMode() && (bool) config('packstub-calls.pop_out', true));
    }

    public function isCallAllowed(Authenticatable $caller, Authenticatable $callee): bool
    {
        if ((string) $caller->getAuthIdentifier() === (string) $callee->getAuthIdentifier()) {
            return false;
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        // Only someone who can open this panel, the way Filament's own middleware decides.
        if ($panel && ! static::canAccess($callee, $panel)) {
            return false;
        }

        if (! $this->canCall) {
            return ! $panel?->hasTenancy() || static::sharesTenant($caller, $callee, $panel);
        }

        // `bool $sharesTenant` (worked out only when asked for) keeps the default's tenant rule in a closure.
        return (bool) $this->evaluate($this->canCall, [
            'caller' => $caller,
            'callee' => $callee,
            'sharesTenant' => fn (): bool => ! $panel?->hasTenancy() || static::sharesTenant($caller, $callee, $panel),
        ]);
    }

    protected static function canAccess(Authenticatable $user, Panel $panel): bool
    {
        if ($user instanceof FilamentUser) {
            return $user->canAccessPanel($panel);
        }

        return app()->environment('local');
    }

    /**
     * Whether two users of a panel with tenancy have a tenant in common (the routes carry
     * no tenant). Inside a tenant, asking the callee about it is enough; otherwise the
     * caller's tenants are read once per request, so a table of Call buttons does not
     * read them per row.
     */
    public static function sharesTenant(Authenticatable $caller, Authenticatable $callee, Panel $panel): bool
    {
        if (! $caller instanceof HasTenants || ! $callee instanceof HasTenants) {
            return false;
        }

        $current = Filament::getTenant();

        if ($current && $callee->canAccessTenant($current)) {
            return true;
        }

        // Kept on the request, so a long-running worker (Octane) never carries it to the next one.
        $cacheKey = 'filament-calls.tenants.'.$panel->getId().'|'.$caller::class.':'.$caller->getAuthIdentifier();
        $mine = request()->attributes->get($cacheKey);

        if ($mine === null) {
            $mine = collect($caller->getTenants($panel))->map(fn (Model $tenant) => $tenant->getMorphClass().':'.$tenant->getKey())->all();
            request()->attributes->set($cacheKey, $mine);
        }

        return collect($callee->getTenants($panel))->contains(fn (Model $tenant) => in_array($tenant->getMorphClass().':'.$tenant->getKey(), $mine, true));
    }

    /** Forget the tenants read during this request (tests, or after changing someone's tenants). */
    public static function flushTenantCache(): void
    {
        foreach (array_keys(request()->attributes->all()) as $key) {
            if (str_starts_with((string) $key, 'filament-calls.tenants.')) {
                request()->attributes->remove($key);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getIceServers(): array
    {
        return $this->evaluate($this->iceServers) ?? config('packstub-calls.ice_servers', []);
    }

    public function hasVideo(): bool
    {
        return (bool) $this->evaluate($this->video) && config('packstub-calls.video', true);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->authenticatedRoutes(function (): void {
                // The core's nine signaling routes, behind the panel's auth and CSRF, plus the pop-out page.
                Calls::routes(prefix: 'filament-calls/calls', middleware: [], as: 'filament-calls.', window: false);
                Route::get('filament-calls/window', WindowController::class)->name('filament-calls.window');
            })
            // Only for a signed-in user: the login page has nobody to call or to be called by.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Filament::auth()->check() ? view('filament-calls::widget')->render() : '');
    }

    public function boot(Panel $panel): void {}
}
