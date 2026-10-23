<?php

namespace App\Filament\Resources\BotEntries\Pages;

use App\Filament\Resources\BotEntries\BotEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBotEntries extends ListRecords
{
    protected static string $resource = BotEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('جواب جديد')];
    }
}
