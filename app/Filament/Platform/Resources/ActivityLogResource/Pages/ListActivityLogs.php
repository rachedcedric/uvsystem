<?php

namespace App\Filament\Platform\Resources\ActivityLogResource\Pages;

use App\Filament\Platform\Resources\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;
}
