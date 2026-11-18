<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $team = [
            'Marco Bianchi' => 'marco',
            'Ana Popescu' => 'ana',
            'Mihai Rusu' => 'mihai',
            'Sara Lindqvist' => 'sara',
            'Tom Becker' => 'tom',
            'Noor Haddad' => 'noor',
            'Elena Rossi' => 'elena',
        ];

        foreach ($team as $name => $login) {
            User::query()->firstOrCreate(['email' => "{$login}@example.com"], ['name' => $name, 'password' => 'password']);
        }
    }
}
