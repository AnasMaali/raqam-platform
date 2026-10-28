<?php

namespace App\Console;

use App\Demo\DemoManifest;
use App\Demo\DemoRemover;
use App\Demo\DemoSeeder;
use App\Media\VariantGenerator;
use App\Models\MediaAsset;
use App\Search\MeiliClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Made-up content for building the website against (development only): about 300 published items of every kind over
 * the past year, writers, guests, organisations, pictures, videos, a year of exchange rates, today's weather, ads,
 * services with made-up prices, FAQ, pages, menus and the home page, all through the services the panel uses.
 *
 * It runs on an empty database only. `--fresh` first takes away what an earlier run wrote (and only that: the run keeps
 * a record of its rows, see DemoManifest), `--remove` only takes it away. Nothing the demo did not write is deleted.
 */
class DemoCommand extends Command
{
    protected $signature = 'raqam:demo
        {--posts=300 : How many posts to write (11 to 2000)}
        {--seed= : Seed of the random choices, to repeat a run exactly}
        {--fresh : Remove the rows of the previous demo run first}
        {--remove : Only remove the rows of the previous demo run}
        {--force : Do not ask before removing}';

    protected $description = 'Fill an empty development database with made-up content for designing the website (never in production)';

    public function handle(DemoSeeder $seeder, DemoRemover $remover, DemoManifest $manifest, VariantGenerator $variants): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('raqam:demo writes made-up content and never runs in production.');

            return self::FAILURE;
        }

        $posts = filter_var($this->option('posts'), FILTER_VALIDATE_INT);
        if ($posts === false || $posts < 11 || $posts > 2000) {
            $this->error('--posts takes a whole number from 11 (one of each kind) to 2000.');

            return self::INVALID;
        }
        $seed = $this->option('seed');
        if ($seed !== null && filter_var($seed, FILTER_VALIDATE_INT) === false) {
            $this->error('--seed takes a whole number.');

            return self::INVALID;
        }
        $seed = $seed === null ? random_int(1, 2_000_000_000) : (int) $seed;

        $user = DB::table('users')->where('user_type', 'staff')->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->value('id');
        if ($user === null) {
            $this->error('No staff account to write as. Create the admin first (php artisan admin:create).');

            return self::FAILURE;
        }

        $manifest->load();
        $replace = $this->option('fresh') || $this->option('remove');

        if (! $replace && ($content = $remover->content()) !== []) {
            $this->error('The database already has content: '.$this->describe($content).'.');
            $this->line($manifest->isEmpty() ? 'raqam:demo fills an empty database only.' : 'Use --fresh to replace the previous demo run, or --remove to take it away.');

            return self::FAILURE;
        }

        if ($replace && ! $this->clearPrevious($remover, $manifest)) {
            return self::FAILURE;
        }
        if ($this->option('remove')) {
            $this->reindex();

            return self::SUCCESS;
        }

        @ini_set('memory_limit', '1G');
        $started = microtime(true);
        $this->info("Writing the demo content (seed {$seed}, {$posts} posts).");

        $this->quietly(function () use ($seeder, $manifest, $user, $posts, $seed, $started) {
            $before = DemoManifest::snapshot();
            // One transaction: a run that fails leaves nothing behind but the picture files, which are removed with it.
            try {
                DB::transaction(function () use ($seeder, $manifest, $user, $posts, $seed, $started) {
                    $seeder->run((int) $user, $posts, $seed, $manifest, fn (string $step) => $this->line(sprintf('→ %s  [%.0f s]', $step, microtime(true) - $started)));
                });
            } catch (\Throwable $e) {
                $seeder->discardFiles();
                throw $e;
            }
            $manifest->recordSince($before);
            $manifest->save();
        });

        $this->line(sprintf('→ picture sizes  [%.0f s]', microtime(true) - $started));
        $made = 0;
        foreach (array_chunk($manifest->keys('media_assets'), 200) as $chunk) {
            foreach (MediaAsset::query()->whereIn('id', $chunk)->get() as $asset) {
                $made += $variants->generate($asset);
            }
        }

        $this->summary($manifest, $made, microtime(true) - $started, $seed);
        $this->reindex();

        return self::SUCCESS;
    }

    /** Takes away the previous run's rows, after checking it is safe and (unless --force) asking. */
    private function clearPrevious(DemoRemover $remover, DemoManifest $manifest): bool
    {
        $foreign = $remover->foreign($manifest);
        if ($foreign !== []) {
            $this->error('The database has content the demo did not write: '.$this->describe($foreign).'. Nothing was removed.');
            $this->line('raqam:demo only removes its own rows; it never replaces real content.');

            return false;
        }
        if ($manifest->isEmpty()) {
            $this->line('No earlier demo run is recorded for this database; nothing to remove.');

            return true;
        }
        if (($wrong = $remover->mismatched($manifest)) > 0) {
            $this->error("The record of the earlier run does not match this database ({$wrong} picture(s) differ): it was rebuilt since. Nothing was removed.");
            $this->line('Delete '.$manifest->path().' if the record is stale.');

            return false;
        }
        if (($blockers = $remover->blockers($manifest)) !== []) {
            array_map(fn ($b) => $this->error($b), $blockers);

            return false;
        }

        $present = $remover->present($manifest);
        $this->warn('These rows of the earlier demo run will be deleted:');
        $this->table(['Table', 'Rows'], collect($present)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $restore = array_merge(array_map(fn ($p) => 'page #'.$p, array_keys($manifest->pages())), array_map(fn ($s) => 'service #'.$s, array_keys($manifest->services())));
        if ($restore !== []) {
            $this->line('Put back as they were before the demo (if unchanged since): '.implode(', ', $restore).'.');
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Not removing without confirmation: run it interactively, or add --force.');

                return false;
            }
            if (! $this->confirm('Delete these rows?', false)) {
                $this->line('Nothing was removed.');

                return false;
            }
        }

        $result = $this->quietly(fn () => $remover->remove($manifest));
        $this->info('Removed: '.($result['removed'] === [] ? 'nothing' : $this->describe($result['removed'])).'.');
        if ($result['restored'] !== []) {
            $this->line('Put back: '.implode(', ', $result['restored']).'.');
        }
        if ($result['kept'] !== []) {
            $this->warn('Changed since the demo, left as they are: '.implode(', ', $result['kept']).'.');
        }

        return true;
    }

    /**
     * Runs with the outside world switched off: no webhook to the site, no IndexNow, no search engine calls per row, no
     * download of YouTube pictures, and no queued jobs (the picture sizes are made right after, the index rebuilt once
     * at the end). The settings come back afterwards.
     */
    private function quietly(\Closure $work): mixed
    {
        $keep = config()->getMany(['raqam.site_webhook.url', 'raqam.indexnow.key', 'raqam.search.key', 'raqam.youtube.fetch_thumbnails', 'queue.default']);
        config([
            'raqam.site_webhook.url' => '', 'raqam.indexnow.key' => '', 'raqam.search.key' => '', 'raqam.youtube.fetch_thumbnails' => false,
            'queue.connections.raqam-demo' => ['driver' => 'null'], 'queue.default' => 'raqam-demo',
        ]);

        try {
            return $work();
        } finally {
            config($keep);
        }
    }

    private function reindex(): void
    {
        if (! MeiliClient::configured()) {
            return;
        }
        $this->line('→ search index');
        if ($this->call('search:reindex') !== self::SUCCESS) {
            $this->warn('The search index was not rebuilt; run php artisan search:reindex when the engine is up.');
        }
    }

    private function summary(DemoManifest $manifest, int $variants, float $seconds, int $seed): void
    {
        $ids = $manifest->keys('posts');
        $kinds = DB::table('posts')->whereIn('id', $ids)->groupBy('kind_code')->orderBy('kind_code')->selectRaw('kind_code, count(*) AS n')->pluck('n', 'kind_code');
        $shorts = DB::table('youtube_videos')->whereIn('video_id', $manifest->keys('videos'))->where('is_short', true)->count();

        $rows = [['posts', count($ids).' ('.$kinds->map(fn ($n, $k) => "{$k} {$n}")->implode(', ').')']];
        foreach (['authors', 'guests', 'organizations', 'sectors', 'eras', 'tags', 'media_assets', 'character_poses', 'playlists', 'podcast_seasons',
            'advertisers', 'ad_campaigns', 'house_ads', 'faqs', 'menu_items', 'home_blocks', 'exchange_rates', 'weather_observations', 'weather_forecasts'] as $table) {
            $rows[] = [$table, (string) count($manifest->keys($table))];
        }
        array_splice($rows, 8, 0, [['videos', count($manifest->keys('videos'))." ({$shorts} shorts)"], ['picture sizes', (string) $variants]]);
        $rows[] = ['pages filled', (string) count($manifest->pages())];
        $rows[] = ['services shown', (string) count($manifest->services())];

        $this->table(['Written', 'Count'], $rows);
        $this->info(sprintf('Done in %.1f s. Repeat this run with --seed=%d. Remove it with --remove.', $seconds, $seed));
        if (! MeiliClient::configured()) {
            $this->line('The search engine is off (no MEILISEARCH_KEY): search runs on PostgreSQL, nothing to index.');
        }
        $this->line('The YouTube addresses are made up: the site shows the titles and pictures, the players will not play.');
    }

    /** @param array<string, int> $counts */
    private function describe(array $counts): string
    {
        return collect($counts)->map(fn ($n, $t) => "{$t} ({$n})")->implode(', ');
    }
}
