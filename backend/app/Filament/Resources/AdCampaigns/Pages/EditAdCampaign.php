<?php

namespace App\Filament\Resources\AdCampaigns\Pages;

use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use App\Models\AdCampaign;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAdCampaign extends EditRecord
{
    use SavesCampaign;

    protected static string $resource = AdCampaignResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AdCampaign $campaign */
        $campaign = $this->getRecord();
        $creatives = $campaign->creativeIds();

        return $data + ['placements' => $campaign->placementCodes(), 'desktop_media_id' => $creatives['desktop'] ?? null, 'mobile_media_id' => $creatives['mobile'] ?? null];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AdCampaign $record */
        return $this->saveCampaign($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('report')->label('التقرير')->url(fn () => CampaignReport::getUrl(['record' => $this->getRecord()]))];
    }
}
