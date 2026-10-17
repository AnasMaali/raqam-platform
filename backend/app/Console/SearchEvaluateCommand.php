<?php

namespace App\Console;

use App\Models\Post;
use App\Search\Experiment\Variants;
use App\Search\MeiliClient;
use App\Search\PostDocument;
use App\Support\ArabicNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spike tool: indexes the fixture corpus four ways, runs the labelled queries against Meilisearch
 * and against the PostgreSQL pg_trgm fallback, and prints recall / precision per query and group.
 * The corpus lives in a transaction that is rolled back, so the database is left untouched.
 */
class SearchEvaluateCommand extends Command
{
    protected $signature = 'search:evaluate {--out= : also write the report (Markdown) to this file} {--keep : keep the eval_* indexes for inspection}';

    protected $description = 'Compare Arabic search quality: Meilisearch variants against pg_trgm';

    private const LIMIT = 10;

    /** @var array<string, string> post id => fixture key */
    private array $keys = [];

    public function handle(): int
    {
        $meili = MeiliClient::fromConfig();
        if (! config('raqam.search.key') || ! $meili->isReachable()) {
            $this->error('Meilisearch is not reachable (check MEILISEARCH_HOST / MEILISEARCH_KEY).');

            return self::FAILURE;
        }

        $fixtures = base_path('tests/Fixtures/search');
        $corpus = json_decode(file_get_contents("$fixtures/corpus.json"), true);
        $queries = json_decode(file_get_contents("$fixtures/queries.json"), true);

        DB::beginTransaction();
        try {
            $posts = $this->insertCorpus($corpus);
            $engines = $this->indexVariants($meili, $posts);
            $results = $this->runQueries($meili, $queries);
        } finally {
            DB::rollBack();
            foreach ($this->option('keep') ? [] : array_keys(Variants::all()) as $variant) {
                try {
                    $meili->deleteIndex($this->uid($variant));
                } catch (\Throwable) {
                }
            }
        }

        $report = "Meilisearch {$meili->version()} · ".count($corpus).' documents · '.count($queries).' queries · top '.self::LIMIT."\n\n"
            .$this->report($engines, $queries, $results);
        $this->line($report);
        if ($out = $this->option('out')) {
            file_put_contents($out, $report);
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Post> */
    private function insertCorpus(array $corpus)
    {
        $media = DB::table('media_assets')->insertGetId([
            'path' => 'eval/cover.jpg', 'original_name' => 'cover.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'checksum_sha256' => str_repeat('a', 64), 'width' => 1, 'height' => 1, 'alt_text' => 'x',
        ]);
        $categories = DB::table('categories')->pluck('id', 'slug');

        foreach ($corpus as $doc) {
            $post = new Post;
            $post->forceFill([
                'kind_code' => 'news', 'category_id' => $categories[$doc['category']], 'title' => $doc['title'],
                'subtitle' => $doc['subtitle'], 'slug' => $doc['key'], 'excerpt' => $doc['excerpt'], 'body' => $doc['body'],
                'cover_media_id' => $media, 'status' => 'published', 'published_at' => now()->subDay(),
            ])->saveQuietly();
            $this->keys[$post->id] = $doc['key'];
        }

        return Post::with('category')->whereIn('id', array_keys($this->keys))->get();
    }

    /** @return array<string, array{strip: bool, synonyms: bool, locale: bool}> */
    private function indexVariants(MeiliClient $meili, $posts): array
    {
        foreach (Variants::all() as $name => $variant) {
            $uid = $this->uid($name);
            try {
                $meili->deleteIndex($uid);
            } catch (\Throwable) {
            }
            $meili->createIndex($uid);
            $meili->updateSettings($uid, PostDocument::settings($variant['synonyms'] ? Variants::synonyms() : [], $variant['locale']));
            $meili->addDocuments($uid, $posts->map(fn (Post $post) => PostDocument::from(
                $post, $variant['strip'] ? Variants::stripArticle(...) : null,
            ))->all());
        }

        return Variants::all();
    }

    /** @return list<array<string, list<string>>> per query: engine => retrieved fixture keys */
    private function runQueries(MeiliClient $meili, array $queries): array
    {
        $results = [];

        foreach ($queries as $i => $query) {
            $normalised = ArabicNormalizer::normalize($query['q']);

            foreach (Variants::all() as $name => $variant) {
                $q = $variant['strip'] ? Variants::stripArticle($normalised) : $normalised;
                $hits = $meili->search($this->uid($name), $q, ['limit' => self::LIMIT, 'attributesToRetrieve' => ['id'], ...($variant['locale'] ? ['locales' => PostDocument::LOCALES] : [])])['hits'];
                $results[$i]["meili: $name"] = array_map(fn ($hit) => $this->keys[$hit['id']], $hits);
            }

            foreach ([0.6, 0.4] as $threshold) {
                DB::statement('SELECT set_config(?, ?, true)', ['pg_trgm.word_similarity_threshold', (string) $threshold]);
                $rows = DB::select(
                    "SELECT id FROM posts WHERE status = 'published' AND deleted_at IS NULL AND ? <% search_text
                     ORDER BY ? <<-> search_text, id LIMIT ".self::LIMIT,
                    [$normalised, $normalised],
                );
                $results[$i]["pg_trgm $threshold"] = array_map(fn ($row) => $this->keys[$row->id], $rows);
            }
        }

        return $results;
    }

    private function report(array $variants, array $queries, array $results): string
    {
        $engines = array_keys($results[0]);
        $out = "### Per query (found / relevant, results returned)\n\n| group | query | note | ".implode(' | ', $engines)." |\n|".str_repeat('---|', 3 + count($engines))."\n";

        $score = [];
        foreach ($queries as $i => $query) {
            $relevant = $query['relevant'];
            $cells = [];
            foreach ($engines as $engine) {
                $returned = $results[$i][$engine];
                $found = count(array_intersect($returned, $relevant));
                $cells[] = $relevant === []
                    ? (count($returned) === 0 ? '✓ 0' : '✗ '.count($returned))
                    : "$found/".count($relevant).' ('.count($returned).')';
                $score[$query['group']][$engine][] = $relevant === []
                    ? ['recall' => null, 'precision' => null, 'rprec' => null, 'clean' => count($returned) === 0]
                    : [
                        'recall' => $found / count($relevant),
                        'precision' => $returned ? $found / count($returned) : 0.0,
                        'rprec' => count(array_intersect(array_slice($returned, 0, count($relevant)), $relevant)) / count($relevant),
                        'clean' => null,
                    ];
            }
            $out .= "| {$query['group']} | {$query['q']} | {$query['note']} | ".implode(' | ', $cells)." |\n";
        }

        $out .= "\n### Per group (recall / precision of the returned list / R-precision = relevant among the first R results; negatives: queries answered with nothing)\n\n| group | n | ".implode(' | ', $engines)." |\n|".str_repeat('---|', 2 + count($engines))."\n";
        $overall = [];
        foreach ($score as $group => $byEngine) {
            $cells = [];
            foreach ($engines as $engine) {
                $rows = $byEngine[$engine];
                if ($rows[0]['recall'] === null) {
                    $cells[] = count(array_filter($rows, fn ($r) => $r['clean'])).'/'.count($rows).' clean';

                    continue;
                }
                $cells[] = $this->means($rows);
                foreach ($rows as $r) {
                    $overall[$engine][] = $r;
                }
            }
            $out .= "| $group | ".count($byEngine[$engines[0]]).' | '.implode(' | ', $cells)." |\n";
        }

        $cells = array_map(fn ($engine) => $this->means($overall[$engine]), $engines);

        return $out.'| **all (without negatives)** | '.count($overall[$engines[0]]).' | '.implode(' | ', $cells)." |\n";
    }

    private function means(array $rows): string
    {
        $mean = fn (string $key) => array_sum(array_column($rows, $key)) / count($rows) * 100;

        return sprintf('%.0f / %.0f / %.0f', $mean('recall'), $mean('precision'), $mean('rprec'));
    }

    private function uid(string $variant): string
    {
        return 'eval_'.preg_replace('/[^a-z]+/', '_', $variant);
    }
}
