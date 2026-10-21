<?php

namespace App\Services;

use App\Api\Presenter;
use App\Jobs\SendSiteWebhook;
use App\Models\Post;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The services and prices as the site gets them (decisions 14.3–14.11): visible services by group, each with its price
 * ready to show, a special offer while it lasts (it ends by itself: answers are never cached past the moment an offer
 * ends), the contact links with the service's name in them, the booking button only when booking is switched on, and
 * Service/Offer structured data.
 */
final class ServiceBoard
{
    private const TTL = 300;

    public function __construct(private Presenter $presenter) {}

    /** @return list<array<string, mixed>> the groups, each with its visible services in the admin's order */
    public function groups(): array
    {
        return $this->remember('groups', function () {
            $services = $this->visible()->orderBy('sort_order')->orderBy('id')->get();
            $this->presenter->preload($services->pluck('cover_media_id'));

            return DB::table('service_groups')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => $g->name_ar, 'services' => $services->where('group_id', $g->id)->map(fn (Service $s) => $this->card($s))->values()->all()])
                ->filter(fn ($g) => $g['services'] !== [])->values()->all();
        });
    }

    /** @return list<array<string, mixed>> the visible services of the «إعلانات» group, for the advertise page */
    public function adPackages(): array
    {
        return collect($this->groups())->firstWhere('name', 'إعلانات')['services'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function detail(string $slug): ?array
    {
        // Only a visible service gets entries of its own in the cache: made-up addresses leave nothing behind.
        $known = $this->remember('slugs', fn () => $this->visible()->pluck('slug')->all());
        if (! in_array($slug, $known, true)) {
            return null;
        }

        return $this->remember('service:'.$slug, function () use ($slug) {
            $s = $this->visible()->where('slug', $slug)->first();
            if ($s === null) {
                return null;
            }
            $meta = $s->seo_meta_id ? DB::table('seo_meta')->where('id', $s->seo_meta_id)->first() : null;

            return $this->card($s) + [
                'description_html' => $s->description,
                'deliverables' => $s->deliverables(),
                'faqs' => DB::table('faqs')->where('service_id', $s->id)->where('is_published', true)->orderBy('sort_order')->get(['question', 'answer'])->map(fn ($f) => (array) $f)->all(),
                'examples' => $this->examples($s),
                'seo' => $this->presenter->seo($meta, $s->name_ar, $s->summary, $s->cover_media_id),
                'structured_data' => $this->structuredData($s),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return ['booking' => ['enabled' => self::bookingOn()], 'currency_note' => 'الأسعار بالعملة المذكورة لكل خدمة.'];
    }

    public static function bookingOn(): bool
    {
        return (bool) DB::table('feature_flags')->where('key', 'booking')->value('is_enabled');
    }

    /** The price as the site shows it (14.2–14.4). The offer, while it lasts, is the price; the regular one is "was". */
    public static function price(Service $s, ?Carbon $now = null): array
    {
        $now ??= now();
        if ($s->price_mode === 'on_request') {
            return ['mode' => 'on_request', 'amount' => null, 'currency' => null, 'symbol' => null, 'note' => null, 'text' => Service::MODES['on_request'], 'offer' => null];
        }
        $currency = DB::table('currencies')->where('code', $s->currency_code)->first(['symbol', 'minor_units']);
        $decimals = (int) ($currency->minor_units ?? 2);
        $offer = $s->sale_price_amount !== null && $s->sale_ends_at !== null && $s->sale_ends_at > $now;
        $minor = $offer ? $s->sale_price_amount : $s->price_amount;
        $shown = Money::display($minor, $decimals).' '.$currency->symbol;

        return [
            'mode' => $s->price_mode,
            'amount' => Money::fromMinor($minor, $decimals),
            'currency' => trim($s->currency_code),
            'symbol' => $currency->symbol,
            'note' => $s->price_note,
            'text' => trim(($s->price_mode === 'from' ? 'يبدأ من ' : '').$shown.' '.($s->price_note ?? '')),
            'offer' => $offer ? [
                'was' => Money::fromMinor($s->price_amount, $decimals),
                'was_text' => Money::display($s->price_amount, $decimals).' '.$currency->symbol,
                'ends_at' => $s->sale_ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ] : null,
        ];
    }

    /** Something about services changed: drop the cached answers after the commit and tell the site (once per save). */
    public static function changed(?string $slug): void
    {
        DB::afterCommit(function () use ($slug) {
            Cache::increment('services:version');
            SendSiteWebhook::notify(['type' => 'services', 'change' => 'service', 'paths' => array_values(array_filter(['/services', $slug ? '/services/'.$slug : null, '/advertise']))]);
        });
    }

    /** @return array<string, mixed> */
    private function card(Service $s): array
    {
        return [
            'id' => $s->id, 'slug' => $s->slug, 'path' => '/services/'.$s->slug, 'group_id' => $s->group_id,
            'name' => $s->name_ar, 'summary' => $s->summary, 'cover' => $this->presenter->media($s->cover_media_id),
            'price' => self::price($s),
            'contact' => ServiceContact::links($s->name_ar),
            'booking' => self::bookingOn() ? ['enabled' => true, 'path' => '/services/'.$s->slug.'/book'] : ['enabled' => false, 'path' => null],
        ];
    }

    /** Published work of the service's section (14.7): the latest six. @return list<array<string, mixed>> */
    private function examples(Service $s): array
    {
        if ($s->section_id === null || ! DB::table('sections')->where('id', $s->section_id)->where('is_visible', true)->exists()) {
            return [];
        }
        $kinds = DB::table('post_kinds')->where('section_id', $s->section_id)->pluck('code');
        $posts = Post::query()->live()->whereIn('kind_code', $kinds)->orderByDesc('published_at')->limit(6)->get();

        return $this->presenter->summaries($posts);
    }

    /** schema.org Service with its Offer (14.11, 23.9). A price on request has no Offer: there is no price to state. */
    private function structuredData(Service $s): array
    {
        $site = (string) (DB::table('settings')->where('key', 'general.site_name')->value('value') ?: 'رَقَم');
        $data = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $s->name_ar, 'description' => $s->summary,
            'provider' => ['@type' => 'NewsMediaOrganization', 'name' => $site], 'areaServed' => ['@type' => 'Place', 'name' => 'فلسطين']];
        $price = self::price($s);
        if ($price['mode'] !== 'on_request') {
            $offer = ['@type' => 'Offer', 'priceCurrency' => $price['currency'], 'availability' => 'https://schema.org/InStock'];
            if ($price['mode'] === 'from') {
                $offer['priceSpecification'] = ['@type' => 'PriceSpecification', 'minPrice' => $price['amount'], 'priceCurrency' => $price['currency']];
            } else {
                $offer['price'] = $price['amount'];
            }
            if ($price['offer'] !== null) {
                $offer['priceValidUntil'] = substr($price['offer']['ends_at'], 0, 10);
            }
            $data['offers'] = $offer;
        }

        return $data;
    }

    private function visible()
    {
        return Service::query()->where('is_active', true);   // the trashed are left out by the model
    }

    private function remember(string $key, \Closure $build): mixed
    {
        $version = (int) Cache::get('services:version', 0);

        return Cache::remember("services:v{$version}:{$key}", $this->ttl(), $build);
    }

    /** Seconds until an answer changes by itself: the next end of an offer, at most TTL. */
    private function ttl(): int
    {
        $next = DB::selectOne('SELECT extract(epoch FROM min(sale_ends_at) - now()) AS s FROM services WHERE sale_ends_at > now() AND deleted_at IS NULL')->s;

        return $next === null ? self::TTL : max(1, min(self::TTL, (int) ceil((float) $next)));
    }
}
