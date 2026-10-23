<?php

use Packstub\Calls\Tests\Fixtures\User;
use Packstub\Calls\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

function user(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret']);
}

function callsRoute(string $name, array $parameters = []): string
{
    return route("filament.admin.filament-calls.{$name}", $parameters);
}
