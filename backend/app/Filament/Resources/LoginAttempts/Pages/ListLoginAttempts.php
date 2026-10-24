<?php

namespace App\Filament\Resources\LoginAttempts\Pages;

use App\Filament\Resources\LoginAttempts\LoginAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListLoginAttempts extends ListRecords
{
    protected static string $resource = LoginAttemptResource::class;
}
