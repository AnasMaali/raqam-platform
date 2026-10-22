<?php

use App\Ads\AdCampaigns;
use App\Models\AdCampaign;
use App\Models\Advertiser;
use App\Models\Organization;

/** Helpers for the ads tests: pictures of a given size and a ready campaign. */

/** A public picture of the library with these pixels (only the row: the API needs no file to describe it). */
function adImage(int $width, int $height, array $overrides = []): int
{
    return insertMedia(['width' => $width, 'height' => $height, ...$overrides]);
}

function adAdvertiser(string $name = 'بنك القدس'): Advertiser
{
    $org = Organization::query()->create(['name' => $name, 'slug' => 'org-'.bin2hex(random_bytes(4))]);

    return Advertiser::query()->create(['organization_id' => $org->id, 'contact_name' => 'المسؤول']);
}

/** The data of a campaign that is valid as it is: header slot, both pictures, live from a minute ago for a week. */
function adCampaignData(array $overrides = []): array
{
    return array_merge([
        'advertiser_id' => $overrides['advertiser_id'] ?? adAdvertiser()->id,
        'name' => 'حملة الخريف',
        'target_url' => 'https://bank.example.ps/offer?utm=raqam',
        'starts_at' => now()->subMinute()->utc()->format('Y-m-d H:i:s'),
        'ends_at' => now()->addWeek()->utc()->format('Y-m-d H:i:s'),
        'priority' => 50,
        'status' => 'active',
        'placements' => ['header_leaderboard'],
        'desktop_media_id' => array_key_exists('desktop_media_id', $overrides) ? null : adImage(970, 90),
        'mobile_media_id' => array_key_exists('mobile_media_id', $overrides) ? null : adImage(320, 100),
    ], $overrides);
}

function adCampaign(array $overrides = []): AdCampaign
{
    return app(AdCampaigns::class)->save(null, adCampaignData($overrides), null);
}

/** A browser a person uses: the counters ignore robots, and an empty agent is one. */
const PERSON_AGENT = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
