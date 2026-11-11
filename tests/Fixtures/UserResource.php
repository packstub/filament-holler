<?php

namespace Packstub\FilamentHoller\Tests\Fixtures;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\FilamentHoller\Actions\CallAction;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->recordActions([CallAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
