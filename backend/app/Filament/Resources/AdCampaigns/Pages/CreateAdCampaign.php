<?php

namespace App\Filament\Resources\AdCampaigns\Pages;

use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAdCampaign extends CreateRecord
{
    use SavesCampaign;

    protected static string $resource = AdCampaignResource::class;

    protected static bool $canCreateAnother = false;

    protected function fillForm(): void
    {
        $this->form->fill(['status' => 'draft', 'priority' => 50]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveCampaign(null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
