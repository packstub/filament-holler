<?php

namespace Packstub\FilamentCalls;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Packstub\Calls\AppContext;
use Packstub\Calls\Contracts\CallContext;

/**
 * The call context of a Filament app: the current panel's guard, its user
 * names and avatars, and the options of the FilamentCallsPlugin registered
 * on it. Outside a panel, or on a panel without the plugin, it answers like
 * a plain app.
 */
class PanelContext implements CallContext
{
    public function __construct(protected AppContext $app) {}

    public function plugin(): ?FilamentCallsPlugin
    {
        $panel = $this->panel();

        if (! $panel?->hasPlugin('filament-calls')) {
            return null;
        }

        /** @var FilamentCallsPlugin $plugin */
        $plugin = $panel->getPlugin('filament-calls');

        return $plugin;
    }

    protected function panel(): ?Panel
    {
        return Filament::getCurrentOrDefaultPanel();
    }

    /** The panel's guard: users of two panels on different guards never ring each other. */
    public function scope(): string
    {
        return $this->panel() ? (string) $this->panel()->getAuthGuard() : $this->app->scope();
    }

    public function currentUserId(): ?string
    {
        if (! $this->panel()) {
            return $this->app->currentUserId();
        }

        $id = Filament::auth()->id();

        return $id === null ? null : (string) $id;
    }

    public function findUser(string $id): ?Authenticatable
    {
        if (! $this->panel()) {
            return $this->app->findUser($id);
        }

        $provider = Filament::auth()->getProvider();

        // An id that cannot be a key ("abc" for an integer key) never reaches the database: Postgres would throw.
        if (! AppContext::couldBeKey($provider, $id)) {
            return null;
        }

        $user = $provider?->retrieveById($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, Authenticatable>
     */
    public function findUsers(array $ids): array
    {
        if (! $this->panel()) {
            return $this->app->findUsers($ids);
        }

        // Skips ids that cannot be a key, like findUser().
        return AppContext::retrieveMany(Filament::auth()->getProvider(), $ids);
    }

    public function who(Authenticatable $user): array
    {
        if (! $this->panel() || ! $user instanceof Model) {
            return $this->app->who($user);
        }

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => Filament::getUserName($user),
            'avatar' => Filament::getUserAvatarUrl($user),
        ];
    }

    public function canCall(Authenticatable $caller, Authenticatable $callee): bool
    {
        return $this->plugin()?->isCallAllowed($caller, $callee) ?? $this->app->canCall($caller, $callee);
    }

    public function iceServers(): array
    {
        return $this->plugin()?->getIceServers() ?? $this->app->iceServers();
    }

    public function hasVideo(): bool
    {
        return $this->plugin()?->hasVideo() ?? $this->app->hasVideo();
    }

    public function maxParticipants(): int
    {
        return $this->plugin()?->getMaxParticipants() ?? $this->app->maxParticipants();
    }
}
