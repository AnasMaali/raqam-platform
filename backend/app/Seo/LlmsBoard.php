<?php

namespace App\Seo;

use App\Settings\SiteProfile;
use Illuminate\Support\Facades\DB;

/**
 * What llms.txt says about the platform (phase 10b, GEO): who it is in a paragraph, and where things are: the special
 * sections, the news categories, the pages that tell who stands behind the news, and the live data. The website writes the
 * Markdown file. Only what a visitor can open.
 */
final class LlmsBoard
{
    /** The pages an answer engine needs to judge the source (28: about, team, editorial and correction policies …). */
    private const TRUST_PAGES = ['about', 'team', 'editorial_policy', 'corrections_policy', 'sponsored_policy', 'contact', 'advertise', 'services'];

    public function __construct(private SiteProfile $profile) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $site = $this->profile->payload();
        $summary = DB::table('settings')->where('key', 'seo.llms_summary')->value('value');
        $link = fn (string $title, string $path, ?string $description = null) => ['title' => $title, 'path' => $path, 'description' => $description ?: null];

        $sections = DB::table('sections')->where('is_visible', true)->orderBy('sort_order')->get()
            ->map(fn ($s) => $link($s->name_ar, SitePaths::section($s->slug), $s->description))->all();
        $categories = DB::table('categories')->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($c) => $link($c->name_ar, SitePaths::category($c->slug), $c->description))->all();
        $order = array_flip(self::TRUST_PAGES);
        $pages = DB::table('pages')->where('is_published', true)->whereIn('system_key', self::TRUST_PAGES)->get()
            ->sortBy(fn ($p) => $order[$p->system_key])->map(fn ($p) => $link($p->title, SitePaths::page($p->slug)))->values()->all();

        return [
            'name' => $site['name'],
            'summary' => filled($summary) ? $summary : $site['seo']['default_description'],
            'groups' => array_values(array_filter([
                ['title' => 'الأقسام الخاصة', 'links' => $sections],
                ['title' => 'التصنيفات الإخبارية', 'links' => $categories],
                ['title' => 'عن المنصة', 'links' => $pages],
                ['title' => 'بيانات حيّة', 'links' => [$link('أسعار العملات', '/currencies'), $link('الطقس', '/weather')]],
            ], fn ($g) => $g['links'] !== [])),
            'feed' => '/feeds/posts',
            'sitemap' => '/sitemap.xml',
        ];
    }
}
