<?php

namespace App\Filament\Resources\AdCampaigns\Pages;

use App\Ads\AdCampaigns;
use App\Ads\AdRejected;
use App\Models\AdCampaign;
use Illuminate\Validation\ValidationException;

/** The create and edit pages save through AdCampaigns; a refusal becomes a message under the field it is about. */
trait SavesCampaign
{
    private function saveCampaign(?AdCampaign $campaign, array $data): AdCampaign
    {
        try {
            return app(AdCampaigns::class)->save($campaign, $data, auth()->id());
        } catch (AdRejected $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?: 'name') => $e->getMessage()]);
        }
    }
}
