<?php

namespace App\Console;

use App\Services\ServiceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds the six starting services of decision 14.5, hidden and without prices ("price on request"): the management sets
 * the prices and texts from the panel and then shows them. Never touches a service that exists (same name or address).
 * No price is written here, ever.
 */
class InstallServicesCommand extends Command
{
    protected $signature = 'services:install';

    protected $description = 'Add the six starting services (hidden, price on request) without touching existing ones';

    /** name => [group, section key or null, slug, summary] */
    public const SERVICES = [
        'حلقة بودكاست' => ['تغطيات إعلامية', 'podcast', 'podcast-episode', 'حلقة بودكاست مصوّرة مع ضيف من عالم الاقتصاد والأعمال، تُنشر على المنصة وقناتها.'],
        'قصة نجاح' => ['تغطيات إعلامية', 'success_stories', 'success-story', 'قصة نجاح لشخص أو شركة بأرقامها البارزة، مكتوبة ومصوّرة.'],
        'تغطية «صُنع في نابلس»' => ['تغطيات إعلامية', 'made_in_nablus', 'made-in-nablus', 'تغطية لمنتج أو مصنع من نابلس بمعرض صور كبير.'],
        'مشاركة في طاولة مستديرة' => ['تغطيات إعلامية', 'round_table', 'round-table', 'مشاركة في جلسة نقاش اقتصادية مع ضيوف آخرين، بمحاور مسجّلة.'],
        'تغطية مبادرة' => ['تغطيات إعلامية', 'initiatives', 'initiative', 'تغطية لمبادرة اقتصادية: فكرتها والجهة المنظمة وحالتها.'],
        'باقات إعلانية' => ['إعلانات', null, 'ad-packages', 'إعلانات مباشرة في أماكن واضحة على المنصة، بمقاسات محجوزة وتقارير مشاهدات ونقرات.'],
    ];

    public function handle(ServiceCatalog $catalog): int
    {
        $added = 0;
        foreach (self::SERVICES as $name => [$group, $section, $slug, $summary]) {
            if (DB::table('services')->where('name_ar', $name)->orWhere('slug', $slug)->exists()) {
                $this->line("skipped: {$name} (exists)");

                continue;
            }
            $catalog->save(null, [
                'name_ar' => $name, 'slug' => $slug, 'summary' => $summary, 'price_mode' => 'on_request', 'is_active' => false,
                'group_id' => DB::table('service_groups')->where('name_ar', $group)->value('id'),
                'section_id' => $section === null ? null : DB::table('sections')->where('key', $section)->value('id'),
            ], null);
            $this->line("added: {$name}");
            $added++;
        }
        $this->info("Services added: {$added}. They are hidden until the management sets their prices and shows them.");

        return self::SUCCESS;
    }
}
