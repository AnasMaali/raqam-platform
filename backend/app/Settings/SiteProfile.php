<?php

namespace App\Settings;

use App\Api\Presenter;
use App\Jobs\SendSiteWebhook;
use Illuminate\Support\Facades\DB;

/**
 * What every page of the website needs to know about the platform (decision 21.13): its name and logo, how to reach it,
 * its social accounts, the default SEO and which switches change what the site shows. Nothing secret is here: no key,
 * no internal mail address, no schedule; only what a visitor could read on the site anyway.
 */
final class SiteProfile
{
    /** The settings this answer is made of: a change to any of them changes every page. */
    public const SETTINGS = [
        'general.site_name', 'general.logo', 'contact.phone', 'contact.whatsapp', 'contact.whatsapp_message', 'contact.email',
        'contact.address', 'contact.map_url', 'seo.title_suffix', 'seo.default_description', 'seo.default_image',
    ];

    /** The switches the website acts on (21.14). The others (multi_admin, chatbot_ai) are the panel's business. */
    public const FEATURES = ['chatbot', 'ad_popup', 'ad_mobile_sticky', 'booking', 'online_payments', 'customer_accounts', 'public_view_counts'];

    public const DEFAULT_NAME = 'رَقَم';

    public function __construct(private Presenter $presenter) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $s = DB::table('settings')->whereIn('key', self::SETTINGS)->pluck('value', 'key');
        $value = fn (string $key) => filled($s[$key] ?? null) ? $s[$key] : null;
        $whatsapp = $value('contact.whatsapp');
        $message = $value('contact.whatsapp_message');

        return [
            'name' => $value('general.site_name') ?? self::DEFAULT_NAME,
            'logo' => $this->presenter->media(($id = $value('general.logo')) ? (int) $id : null),
            'contact' => [
                'phone' => $value('contact.phone'),
                'whatsapp' => $whatsapp,
                'whatsapp_message' => $message,
                'whatsapp_url' => $whatsapp ? 'https://wa.me/'.ltrim($whatsapp, '+').($message ? '?text='.rawurlencode($message) : '') : null,
                'email' => $value('contact.email'),
                'address' => $value('contact.address'),
                'map_url' => $value('contact.map_url'),
            ],
            'social' => DB::table('site_social_links')->join('social_platforms', 'social_platforms.code', '=', 'site_social_links.platform_code')
                ->where('site_social_links.is_visible', true)->orderBy('site_social_links.sort_order')->orderBy('social_platforms.sort_order')
                ->get(['social_platforms.code', 'social_platforms.name', 'site_social_links.url'])
                ->map(fn ($r) => ['platform' => $r->code, 'name' => $r->name, 'url' => $r->url])->all(),
            'seo' => [
                'title_suffix' => $value('seo.title_suffix'),
                'default_description' => $value('seo.default_description'),
                'default_image' => $this->presenter->media(($id = $value('seo.default_image')) ? (int) $id : null),
            ],
            'features' => DB::table('feature_flags')->whereIn('key', self::FEATURES)->pluck('is_enabled', 'key')
                ->map(fn ($on) => (bool) $on)->sortKeys()->all(),
        ];
    }

    /** Tells the website that what every page shows about the platform changed. */
    public static function changed(string $change): void
    {
        SendSiteWebhook::notify(['type' => 'site', 'change' => $change, 'paths' => []]);
    }
}
