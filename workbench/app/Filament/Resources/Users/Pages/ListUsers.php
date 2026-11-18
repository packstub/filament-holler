<?php

namespace Workbench\App\Filament\Resources\Users\Pages;

use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Users\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected ?string $subheading = 'Call anyone on the team, right from their row.';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
