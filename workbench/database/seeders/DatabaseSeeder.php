<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Ion Caliman' => 'ion', 'Ana Popescu' => 'ana', 'Mihai Rusu' => 'mihai'] as $name => $login) {
            User::query()->firstOrCreate(['email' => "{$login}@example.com"], ['name' => $name, 'password' => 'password']);
        }
    }
}
