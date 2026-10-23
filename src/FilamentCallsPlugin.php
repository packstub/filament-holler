<?php

namespace Packstub\Calls;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Packstub\Calls\Http\CallsController;
use Packstub\Calls\Http\WindowController;

class FilamentCallsPlugin implements Plugin
{
    use EvaluatesClosures;

    protected ?Closure $canCall = null;

    /** @var array<int, array<string, mixed>> | Closure | null */
    protected array|Closure|null $iceServers = null;

    protected bool|Closure $video = true;

    protected bool|Closure|null $popOut = null;

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
     * Decide who may call whom. Receives the caller and the callee; the default
     * lets any two different users of the panel call each other.
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

    public function isPopOut(): bool
    {
        return $this->evaluate($this->popOut) ?? ! Filament::getCurrentOrDefaultPanel()->hasSpaMode();
    }

    public function isCallAllowed(Authenticatable $caller, Authenticatable $callee): bool
    {
        if ((string) $caller->getAuthIdentifier() === (string) $callee->getAuthIdentifier()) {
            return false;
        }

        if (! $this->canCall) {
            return true;
        }

        return (bool) $this->evaluate($this->canCall, ['caller' => $caller, 'callee' => $callee]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getIceServers(): array
    {
        return $this->evaluate($this->iceServers) ?? config('filament-calls.ice_servers', []);
    }

    public function hasVideo(): bool
    {
        return (bool) $this->evaluate($this->video) && config('filament-calls.video', true);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->authenticatedRoutes(function (): void {
                Route::prefix('filament-calls')->name('filament-calls.')->group(function (): void {
                    Route::get('poll', [CallsController::class, 'poll'])->name('poll');
                    Route::post('calls', [CallsController::class, 'store'])->name('store');
                    Route::post('calls/{call}/answer', [CallsController::class, 'answer'])->name('answer');
                    Route::post('calls/{call}/decline', [CallsController::class, 'decline'])->name('decline');
                    Route::post('calls/{call}/hangup', [CallsController::class, 'hangup'])->name('hangup');
                    Route::post('calls/{call}/signals', [CallsController::class, 'signal'])->name('signal');
                    Route::post('calls/{call}/leave', [CallsController::class, 'leave'])->name('leave');
                    Route::get('window', WindowController::class)->name('window');
                });
            })
            // Only for a signed-in user: the login page has nobody to call or to be called by.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Filament::auth()->check() ? view('filament-calls::widget')->render() : '');
    }

    public function boot(Panel $panel): void {}
}
