<?php

namespace App\Console;

use App\Search\Indexes\PostsIndex;
use App\Search\MeiliClient;
use App\Search\PlainText;
use App\Search\SearchForm;
use App\Search\SearchIndexer;
use App\Search\SynonymBook;
use Illuminate\Console\Command;

/**
 * Development tool: measures the search form on the labelled corpus in tests/Fixtures/search against the real
 * engine, in temporary indexes, for the variants of the phase-5 experiment (the sound feminine plural, with and
 * without the starter synonyms). Prints Markdown tables; docs/search/arabic-search.md keeps the figures.
 */
class SearchEvaluateCommand extends Command
{
    protected $signature = 'search:evaluate';

    protected $description = 'Development: measure recall and precision of the search variants on the labelled corpus';

    private const VARIANTS = [
        'current' => ['fold' => false, 'synonyms' => false],
        'feminine' => ['fold' => true, 'synonyms' => false],
        'current+synonyms' => ['fold' => false, 'synonyms' => true],
        'feminine+synonyms' => ['fold' => true, 'synonyms' => true],
    ];

    public function handle(): int
    {
        if (app()->isProduction() || ! MeiliClient::configured()) {
            $this->error('Development only, with the engine configured.');

            return self::FAILURE;
        }

        $dir = base_path('tests/Fixtures/search');
        $feminine = json_decode((string) file_get_contents($dir.'/feminine.json'), true);
        $documents = [...json_decode((string) file_get_contents($dir.'/corpus.json'), true), ...$feminine['documents']];
        $queries = [...json_decode((string) file_get_contents($dir.'/queries.json'), true), ...$feminine['queries']];
        $meili = MeiliClient::fromConfig();
        $keys = array_column($documents, 'key');

        $results = [];
        $flag = config('raqam.search.feminine_plural');
        try {
            foreach (self::VARIANTS as $name => $variant) {
                config(['raqam.search.feminine_plural' => $variant['fold']]);
                $uid = SearchIndexer::uid('eval_'.str_replace('+', '_', $name));
                $meili->deleteIndex($uid);
                $meili->createIndex($uid);
                $synonyms = $variant['synonyms'] ? SynonymBook::mapOf(InstallSynonymsCommand::STARTER) : [];
                $meili->updateSettings($uid, [...(new PostsIndex)->settings(), 'synonyms' => (object) $synonyms]);
                $meili->addDocuments($uid, array_map(fn (array $d, int $i) => [
                    'id' => $i + 1, 'kind' => 'news', 'categories' => [], 'published_at' => 0,
                    'title_n' => SearchForm::text($d['title']), 'subtitle_n' => SearchForm::text($d['subtitle']),
                    'excerpt_n' => SearchForm::text($d['excerpt']), 'body_n' => SearchForm::text(PlainText::fromHtml($d['body'])),
                    'tags_n' => '', 'people_n' => '', 'category_n' => SearchForm::text(str_replace('-', ' ', $d['category'])),
                ], $documents, array_keys($documents)), wait: true);

                foreach ($queries as $i => $q) {
                    $hits = $meili->multiSearch([['indexUid' => $uid, 'q' => SearchForm::text($q['q']), 'locales' => ['ara'], 'limit' => 10]])[0]['hits'];
                    $results[$name][$i] = array_map(fn ($h) => $keys[$h['id'] - 1], $hits);
                }
                $meili->deleteIndex($uid);
            }
        } finally {
            config(['raqam.search.feminine_plural' => $flag]);
        }

        $this->line(count($documents).' documents · '.count($queries).' queries · top 10');
        $this->table(['group', 'query', 'note', ...array_keys(self::VARIANTS)], $this->perQuery($queries, $results, ['sound', 'feminine', 'feminine-trap']));
        $this->table(['group', 'n', ...array_keys(self::VARIANTS)], $this->perGroup($queries, $results));

        return self::SUCCESS;
    }

    /** @return list<list<string>> */
    private function perQuery(array $queries, array $results, array $groups): array
    {
        $rows = [];
        foreach ($queries as $i => $q) {
            if (! in_array($q['group'], $groups, true)) {
                continue;
            }
            $row = [$q['group'], $q['q'], $q['note'] ?? ''];
            foreach (array_keys(self::VARIANTS) as $name) {
                $found = count(array_intersect($results[$name][$i], $q['relevant']));
                $row[] = $found.'/'.count($q['relevant']).' ('.count($results[$name][$i]).')';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Recall / precision of the returned list / R-precision, in per cent; negatives: queries answered with nothing. */
    private function perGroup(array $queries, array $results): array
    {
        $groups = [];
        foreach ($queries as $i => $q) {
            $groups[$q['group']][] = $i;
        }
        $groups['all (without negatives)'] = array_keys(array_filter($queries, fn ($q) => $q['group'] !== 'negative'));

        $rows = [];
        foreach ($groups as $group => $indexes) {
            $row = [$group, (string) count($indexes)];
            foreach (array_keys(self::VARIANTS) as $name) {
                if ($group === 'negative') {
                    $row[] = count(array_filter($indexes, fn ($i) => $results[$name][$i] === [])).'/'.count($indexes).' clean';

                    continue;
                }
                [$recall, $precision, $rp] = [[], [], []];
                foreach ($indexes as $i) {
                    $relevant = $queries[$i]['relevant'];
                    $got = $results[$name][$i];
                    $recall[] = count(array_intersect($got, $relevant)) / max(1, count($relevant));
                    $precision[] = $got === [] ? 0 : count(array_intersect($got, $relevant)) / count($got);
                    $rp[] = count(array_intersect(array_slice($got, 0, count($relevant)), $relevant)) / max(1, count($relevant));
                }
                $avg = fn (array $v) => (string) round(100 * array_sum($v) / max(1, count($v)));
                $row[] = $avg($recall).' / '.$avg($precision).' / '.$avg($rp);
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
