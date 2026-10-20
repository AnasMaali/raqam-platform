<?php

namespace App\Filament\Resources\Synonyms\Pages;

use App\Filament\Resources\Synonyms\SynonymGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSynonymGroups extends ListRecords
{
    protected static string $resource = SynonymGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('مجموعة جديدة')];
    }
}
