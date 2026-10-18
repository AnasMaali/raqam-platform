<?php

namespace App\Filament\Resources\Authors\Pages;

use App\Filament\Resources\Authors\AuthorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAuthors extends ListRecords
{
    protected static string $resource = AuthorResource::class;

    public function getSubheading(): ?string
    {
        return 'المواد التي لا يُحدَّد لها كاتب تظهر باسم «فريق رَقَم».';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('كاتب جديد')];
    }
}
