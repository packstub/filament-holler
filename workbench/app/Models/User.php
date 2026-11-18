<?php

namespace Workbench\App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * A teammate. The role, the presence and the photo are fixed per e-mail (PROFILES) instead of
 * columns, so the workbench keeps Laravel's own users table. The photos are AI-generated faces
 * (nobody real) in workbench/resources/avatars; someone without one gets their initials. Both
 * are served by the panel provider, so nothing is fetched from outside.
 */
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** e-mail => [role, presence (online | away | offline), photo or a colour for the initials]. */
    public const PROFILES = [
        'marco@example.com' => ['Product designer', 'online', 'marco'],
        'ana@example.com' => ['Design lead', 'online', 'ana'],
        'mihai@example.com' => ['Backend engineer', 'online', 'mihai'],
        'sara@example.com' => ['Customer support', 'online', 'sara'],
        'tom@example.com' => ['Sales', 'away', 'tom'],
        'noor@example.com' => ['Finance', 'away', '#0d9488'],
        'elena@example.com' => ['Marketing', 'offline', '#7c3aed'],
    ];

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        $avatar = self::PROFILES[$this->email][2] ?? '#64748b';

        if (! str_starts_with($avatar, '#')) {
            return url("/workbench/avatars/{$avatar}.jpg");
        }

        $initials = Str::of($this->name)->explode(' ')->map(fn (string $word) => mb_substr($word, 0, 1))->take(2)->join('');

        return url('/workbench/avatars/'.$initials.'-'.ltrim($avatar, '#').'.svg');
    }

    public function role(): ?string
    {
        return self::PROFILES[$this->email][0] ?? null;
    }

    public function presence(): string
    {
        return self::PROFILES[$this->email][1] ?? 'offline';
    }
}
