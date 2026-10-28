<?php

namespace Workbench\App\Filament\Resources\Users;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\FilamentCalls\Actions\CallAction;
use Workbench\App\Filament\Resources\Users\Pages\ListUsers;
use Workbench\App\Models\User;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Team';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
            ])
            ->recordActions([
                CallAction::make()->button(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
