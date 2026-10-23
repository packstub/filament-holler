<?php

namespace Packstub\Calls;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class Calls
{
    /**
     * How a user appears to the other side: the panel's own name and avatar.
     *
     * @return array{id: string, name: string, avatar: ?string}
     */
    public static function who(Authenticatable $user): array
    {
        if (! $user instanceof Model) {
            return ['id' => (string) $user->getAuthIdentifier(), 'name' => __('filament-calls::calls.someone'), 'avatar' => null];
        }

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => Filament::getUserName($user),
            'avatar' => Filament::getUserAvatarUrl($user),
        ];
    }

    /** The user with this id in the current panel's guard, or null. */
    public static function findUser(string $id): ?Authenticatable
    {
        $user = Filament::auth()->getProvider()->retrieveById($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    public static function currentUserId(): string
    {
        return (string) Filament::auth()->id();
    }
}
