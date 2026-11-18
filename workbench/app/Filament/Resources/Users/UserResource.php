<?php

namespace Workbench\App\Filament\Resources\Users;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\FilamentHoller\Actions\CallAction;
use Workbench\App\Filament\Resources\Users\Pages\ListUsers;
use Workbench\App\Models\User;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $modelLabel = 'teammate';

    protected static ?string $pluralModelLabel = 'team';

    protected static ?string $slug = 'users';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->state(fn (User $record): ?string => $record->getFilamentAvatarUrl())
                    ->circular()
                    ->imageSize(40)
                    ->grow(false),
                TextColumn::make('name')
                    ->weight('semibold')
                    ->description(fn (User $record): ?string => $record->role()),
                TextColumn::make('email')
                    ->color('gray'),
                TextColumn::make('presence')
                    ->label('Status')
                    ->state(fn (User $record): string => $record->presence())
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'away' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                CallAction::make()->button(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
