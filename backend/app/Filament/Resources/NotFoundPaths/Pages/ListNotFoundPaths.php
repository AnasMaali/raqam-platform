<?php

namespace App\Filament\Resources\NotFoundPaths\Pages;

use App\Filament\Resources\NotFoundPaths\NotFoundPathResource;
use Filament\Resources\Pages\ListRecords;

class ListNotFoundPaths extends ListRecords
{
    protected static string $resource = NotFoundPathResource::class;
}
