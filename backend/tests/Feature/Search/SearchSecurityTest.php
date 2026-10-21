<?php

use App\Jobs\SyncSearchDocuments;
use App\Search\Searcher;
use App\Search\SearchQuery;
use App\Search\SearchStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Phase 5 security review (private/phase5-security-report.md): each finding has its test here.

uses(RefreshDatabase::class);

beforeEach(function () {
    ensurePostKinds();
    Cache::flush();
    config(['raqam.search.key' => '']);
    $this->gold = insertPost(publishedPostValues(['title' => 'أسعار الذهب اليوم']));
});

function searchFor(string $q, array $extra = [])
{
    return test()->flushHeaders()->getJson('/api/v1/search?'.http_build_query(['q' => $q, ...$extra]));
}

// ---- injection ------------------------------------------------------------------------------------

test('SQL in the query is only text for the database search', function (string $q) {
    searchFor($q)->assertOk();

    expect(DB::table('posts')->where('id', $this->gold)->exists())->toBeTrue();
})->with([
    "'; DROP TABLE posts; --",
    "ذهب' OR '1'='1",
    'ذهب\\',
    "ذهب\u{0000}",
    'ذهب%_\\%',
]);

test('engine filter syntax in the query is only text for the engine', function () {
    config(['raqam.search.key' => 'k']);
    Http::swap(new Factory);
    Http::fake(['*/multi-search' => Http::response(['results' => array_fill(0, 5, ['hits' => [], 'totalHits' => 0])])]);

    searchFor('ذهب" OR kind = "awareness')->assertOk();

    // The words go in q (plain text for the engine); the filter stays the one built from checked values only.
    Http::assertSent(fn ($r) => $r['queries'][0]['q'] === 'ذهب" or kind = "awareness' && $r['queries'][0]['filter'] === []);
});

test('a query that is only marks or symbols finds nothing and does not reach the engine', function () {
    config(['raqam.search.key' => 'k']);
    Http::swap(new Factory);
    Http::fake();

    searchFor(str_repeat("\u{064B}", 100))->assertOk()->assertJsonPath('data.posts', []);
    searchFor('!!!***')->assertOk()->assertJsonPath('data.posts', []);

    Http::assertNothingSent();
});

// ---- bounded work ----------------------------------------------------------------------------------

test('the database search reads at most ten words of a query, as the engine does', function () {
    $q = implode(' ', array_map(fn ($i) => 'كلمة'.$i, range(1, 40)));
    $likes = 0;
    DB::listen(function ($query) use (&$likes) {
        str_contains($query->sql, 'search_text') && $likes = max($likes, substr_count(strtolower($query->sql), 'ilike'));
    });

    searchFor(mb_substr($q, 0, 120))->assertOk();

    expect($likes)->toBeLessThanOrEqual(SearchQuery::MAX_TERMS);
});

test('statistics take a bounded number of new words a day, so a flood of made-up queries cannot fill the table', function () {
    config(['raqam.search.new_terms_per_day' => 5]);
    SearchStats::record('اسعار الذهب', false);

    foreach (range(1, 20) as $i) {
        SearchStats::record('كلمة عشوائية '.$i, true);
    }
    SearchStats::record('اسعار الذهب', false);   // a word already known is still counted

    expect(DB::table('search_terms')->count())->toBe(5)
        ->and(DB::table('search_stats_daily')->join('search_terms', 'search_terms.id', '=', 'term_id')->where('normalized_term', 'اسعار الذهب')->value('searches'))->toBe(2);
});

// ---- nothing hidden leaks ------------------------------------------------------------------------------

test('no field of an answer exposes what an index holds beyond the shown text', function () {
    $body = searchFor('الذهب')->assertOk()->getContent();

    foreach (['title_n', 'body_n', 'people_n', '_matchesPosition', 'search_text', 'meilisearch.key'] as $internal) {
        expect($body)->not->toContain($internal);
    }
});

test('a failed index job leaves no part of the engine key in its error, even with arguments kept in traces', function () {
    $key = 'meili-secret-key-0123456789abcdef';
    config(['raqam.search.key' => $key, 'raqam.search.host' => 'http://127.0.0.1:9']);
    ini_set('zend.exception_ignore_args', '0');
    $job = (new SyncSearchDocuments('posts', [$this->gold]))->setJob(new class extends Job implements Illuminate\Contracts\Queue\Job
    {
        public function getJobId()
        {
            return '1';
        }

        public function getRawBody()
        {
            return '';
        }

        public function attempts()
        {
            return 1;
        }

        public function getConnectionName()
        {
            return 'redis';
        }
    });

    try {
        $job->handle();
        $this->fail('should fail');
    } catch (Throwable $e) {
        $text = (string) $e;
        for ($e2 = $e; $e2; $e2 = $e2->getPrevious()) {
            $text .= (string) $e2;
        }
        expect($text)->not->toContain(substr($key, 0, 10));
    }
});

test('the engine key is not part of any queued job', function () {
    config(['raqam.search.key' => 'meili-secret-key-0123456789abcdef']);

    $payload = serialize(new SyncSearchDocuments('posts', [1]));

    expect($payload)->not->toContain('meili-secret');
});

test('the search answer is JSON, never HTML a browser would render', function () {
    searchFor('<script>alert(1)</script>')->assertOk()->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('data.query', '<script>alert(1)</script>');
});

test('the searcher\'s own answer for the engine path never includes a draft, even when the engine returns one', function () {
    $draft = insertPost(['title' => 'الذهب مسودة']);
    config(['raqam.search.key' => 'k', 'raqam.search.prefix' => 'p_']);
    Http::swap(new Factory);
    Http::fake(['*/multi-search' => Http::response(['results' => [
        ['hits' => [['id' => $draft, 'title_n' => 'ذهب مسوده'], ['id' => $this->gold, 'title_n' => 'اسعار ذهب يوم']], 'totalHits' => 2],
        ...array_fill(0, 4, ['hits' => []]),
    ]])]);

    expect(array_column(app(Searcher::class)->search(new SearchQuery('الذهب'))->posts, 'id'))->toBe([$this->gold]);
});
