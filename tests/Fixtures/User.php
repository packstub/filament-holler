<?php

namespace Packstub\FilamentCalls\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** Everyone but "Locked", for the tests that need someone who cannot open the panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->name !== 'Locked';
    }
}
