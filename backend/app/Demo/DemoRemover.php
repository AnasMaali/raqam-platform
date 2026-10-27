<?php

namespace App\Demo;

use App\Ads\AdBoard;
use App\Analytics\StatsFlusher;
use App\Chat\KnowledgeBase;
use App\Markets\RateBoard;
use App\Media\MediaStorage;
use App\Models\Page;
use App\Navigation\HomeLayout;
use App\Navigation\MenuBoard;
use App\Services\ServiceBoard;
use App\Weather\WeatherBoard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Looks at what the database holds and takes the demo's own rows away again (raqam:demo --fresh / --remove). Only rows
 * named in the manifest are deleted; the pages and services the demo filled go back to how they were, unless somebody
 * changed them since. Staff, settings, reference data and whatever raqam:install wrote are never touched.
 */
class DemoRemover
{
    /** Tables whose rows mean "this database already has content". @var array<string, ?string> table => extra condition */
    public const CONTENT = [
        'posts' => null, 'videos' => null, 'authors' => null, 'guests' => null, 'organizations' => null, 'sectors' => null, 'eras' => null,
        'tags' => null, 'media_assets' => null, 'playlists' => null, 'podcast_seasons' => null, 'advertisers' => null, 'ad_campaigns' => null,
        'house_ads' => null, 'character_poses' => null, 'menu_items' => null, 'faqs' => 'service_id IS NULL',
        'exchange_rates' => null, 'weather_observations' => null,
    ];

    /** Real market data may arrive beside the demo's (currency:fetch, weather:fetch): it stays, and is no reason to stop. */
    public const LIVE_DATA = ['exchange_rates', 'weather_observations'];

    /** The columns of a service the demo changes. */
    private const SERVICE_COLUMNS = ['price_mode', 'price_amount', 'currency_code', 'price_note', 'sale_price_amount', 'sale_ends_at', 'cover_media_id', 'is_active'];

    public function __construct(private MediaStorage $storage, private HomeLayout $home) {}

    /** @return array<string, int> table => rows, for the tables that have any */
    public function content(): array
    {
        $counts = [];
        foreach (self::CONTENT as $table => $where) {
            $n = DB::table($table)->when($where, fn ($q) => $q->whereRaw($where))->count();
            $n > 0 && $counts[$table] = $n;
        }

        return $counts;
    }

    /** @return array<string, int> table => rows the demo did not write (live market data left out) */
    public function foreign(DemoManifest $manifest): array
    {
        $counts = [];
        foreach (self::CONTENT as $table => $where) {
            if (in_array($table, self::LIVE_DATA, true)) {
                continue;
            }
            $key = DemoManifest::TABLES[$table];
            $ours = $manifest->keys($table);
            $n = DB::table($table)->when($where, fn ($q) => $q->whereRaw($where))
                ->when($ours !== [], fn ($q) => $q->whereRaw("({$key})::text <> ALL (?::text[])", [self::pgArray($ours)]))->count();
            $n > 0 && $counts[$table] = $n;
        }

        return $counts;
    }

    /** @return array<string, int> table => rows of the manifest still in the database */
    public function present(DemoManifest $manifest): array
    {
        $counts = [];
        foreach (DemoManifest::TABLES as $table => $key) {
            $ours = $manifest->keys($table);
            $n = $ours === [] ? 0 : DB::table($table)->whereRaw("({$key})::text = ANY (?::text[])", [self::pgArray($ours)])->count();
            $n > 0 && $counts[$table] = $n;
        }

        return $counts;
    }

    /** Pictures of the manifest whose id now holds another file: the database was rebuilt since, and the ids mean nothing. */
    public function mismatched(DemoManifest $manifest): int
    {
        $wrong = 0;
        foreach (array_chunk($manifest->checksums(), 1000, true) as $chunk) {
            foreach (DB::table('media_assets')->whereIn('id', array_keys($chunk))->pluck('checksum_sha256', 'id') as $id => $checksum) {
                $wrong += trim($checksum) === $chunk[$id] ? 0 : 1;
            }
        }

        return $wrong;
    }

    /** @return list<string> why the rows cannot be removed (something that is not the demo's points at them) */
    public function blockers(DemoManifest $manifest): array
    {
        $posts = $manifest->keys('posts');
        $issues = $posts === [] ? 0 : DB::table('newsletter_issue_posts')->whereRaw('post_id::text = ANY (?::text[])', [self::pgArray($posts)])->distinct()->count('issue_id');

        return $issues > 0 ? ["{$issues} newsletter issue(s) include demo posts; delete those issues in the panel first."] : [];
    }

    /** @return array{removed: array<string, int>, restored: list<string>, kept: list<string>} */
    public function remove(DemoManifest $manifest): array
    {
        $removed = [];
        $restored = [];
        $kept = [];
        $files = [];

        DB::transaction(function () use ($manifest, &$removed, &$restored, &$kept, &$files) {
            // The home page through its own screen's door, so the order closes up and the version moves.
            $blocks = $manifest->keys('home_blocks');
            if ($blocks !== []) {
                $layout = $this->home->load();
                $left = array_values(array_filter($layout, fn ($b) => ! in_array((string) $b['id'], $blocks, true)));
                if (count($left) !== count($layout)) {
                    $this->home->save($left, null);
                    $removed['home_blocks'] = count($layout) - count($left);
                }
            }

            // Services and pages first: they may point at demo pictures.
            foreach ($manifest->services() as $id => $before) {
                $label = 'service '.DB::table('services')->where('id', $id)->value('slug');
                if (self::serviceHash((int) $id) !== $before['demo_hash']) {
                    $kept[] = $label;

                    continue;
                }
                DB::table('services')->where('id', $id)->update(array_intersect_key($before['row'], array_flip(self::SERVICE_COLUMNS)));
                DB::table('service_deliverables')->where('service_id', $id)->delete();
                foreach ($before['deliverables'] as $i => $label2) {
                    DB::table('service_deliverables')->insert(['service_id' => $id, 'sort_order' => $i + 1, 'label' => $label2]);
                }
                DB::table('faqs')->where('service_id', $id)->delete();
                foreach ($before['faqs'] as $i => $faq) {
                    DB::table('faqs')->insert(['service_id' => $id, 'sort_order' => $i + 1] + $faq);
                }
                $restored[] = $label;
            }
            foreach ($manifest->pages() as $id => $before) {
                $page = Page::query()->find($id);
                if ($page === null) {
                    continue;
                }
                if (md5((string) $page->body) !== $before['demo_hash']) {
                    $kept[] = 'page '.$page->slug;

                    continue;
                }
                $page->fill(['body' => $before['body'], 'is_published' => $before['is_published'], 'updated_by' => $before['updated_by']])->save();
                $restored[] = 'page '.$page->slug;
            }

            $media = $manifest->keys('media_assets');
            foreach (array_chunk($media, 1000) as $chunk) {
                foreach (DB::table('media_assets')->whereIn('id', $chunk)->get(['id', 'disk', 'visibility', 'path']) as $asset) {
                    $files["{$asset->disk}|{$asset->visibility}"][] = $asset->path;
                    array_push($files["{$asset->disk}|{$asset->visibility}"], ...DB::table('media_variants')->where('media_id', $asset->id)->pluck('path')->all());
                }
            }

            foreach (DemoManifest::TABLES as $table => $key) {
                $ours = $manifest->keys($table);
                if ($table === 'home_blocks' || $ours === []) {
                    continue;
                }
                $n = 0;
                foreach (array_chunk($ours, 1000) as $chunk) {
                    $n += DB::table($table)->whereRaw("({$key})::text = ANY (?::text[])", [self::pgArray($chunk)])->delete();
                }
                $n > 0 && $removed[$table] = $n;
            }

            DB::statement('REFRESH MATERIALIZED VIEW post_view_totals');
        });

        // The files only once the rows are surely gone.
        foreach ($files as $where => $paths) {
            [$disk, $visibility] = explode('|', $where);
            $this->storage->disk($disk, $visibility)->delete($paths);
        }

        self::refreshCaches();
        $manifest->forget();

        return ['removed' => $removed, 'restored' => $restored, 'kept' => $kept];
    }

    /** Every cached answer the demo's rows could be in. */
    public static function refreshCaches(): void
    {
        RateBoard::changed('demo');
        WeatherBoard::changed();
        AdBoard::changed('demo');
        ServiceBoard::changed(null, everyPage: true);
        MenuBoard::changed('demo');
        KnowledgeBase::changed();
        Cache::forever(StatsFlusher::VERSION, (int) Cache::get(StatsFlusher::VERSION, 0) + 1);
        Cache::forever(HomeLayout::VERSION, (int) Cache::get(HomeLayout::VERSION, 0) + 1);
    }

    /** @return array{row: array<string, mixed>, deliverables: list<string>, faqs: list<array<string, mixed>>} a service as it is now */
    public static function serviceState(int $id): array
    {
        return [
            'row' => (array) DB::table('services')->where('id', $id)->first(self::SERVICE_COLUMNS),
            'deliverables' => DB::table('service_deliverables')->where('service_id', $id)->orderBy('sort_order')->pluck('label')->all(),
            'faqs' => DB::table('faqs')->where('service_id', $id)->orderBy('sort_order')->get(['question', 'answer', 'is_published'])->map(fn ($f) => (array) $f)->all(),
        ];
    }

    /** What a service looks like, as one hash: equal before removal means nobody changed it since the demo. */
    public static function serviceHash(int $id): string
    {
        return md5(json_encode(self::serviceState($id), JSON_UNESCAPED_UNICODE));
    }

    /** A PostgreSQL text array literal, so a long list of keys is one parameter. */
    private static function pgArray(array $keys): string
    {
        return '{'.implode(',', array_map(fn ($k) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $k).'"', $keys)).'}';
    }
}
