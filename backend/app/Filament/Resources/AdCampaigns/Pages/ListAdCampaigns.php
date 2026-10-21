<?php

namespace App\Filament\Resources\AdCampaigns\Pages;

use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdCampaigns extends ListRecords
{
    protected static string $resource = AdCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('حملة جديدة')];
    }
}
