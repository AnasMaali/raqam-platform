<?php

namespace App\Filament\Resources\Advertisers\Pages;

use App\Filament\Forms\AdvertiserSelect;
use App\Filament\Resources\Advertisers\AdvertiserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAdvertisers extends ManageRecords
{
    protected static string $resource = AdvertiserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('معلن جديد')->createAnother(false)->using(fn (array $data) => AdvertiserSelect::create($data))];
    }
}
