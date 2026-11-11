<?php

use Packstub\FilamentHoller\Tests\Fixtures\User;
use Packstub\FilamentHoller\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

function user(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret']);
}

function callsRoute(string $name, array $parameters = []): string
{
    return route("filament.admin.filament-holler.{$name}", $parameters);
}
