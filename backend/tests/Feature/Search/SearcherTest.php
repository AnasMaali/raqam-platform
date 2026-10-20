<?php

use App\Models\Character;
use App\Search\Highlighter;
use App\Search\MeiliClient;
use App\Search\Searcher;
use App\Search\SearchIndexer;
use App\Search\SearchQuery;
use App\Search\SearchResults;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    ensurePostKinds();
    Cache::flush();
    $this->parent = insertCategory('فئة-مال');
    $this->child = insertCategory('فئة-مصارف', $this->parent);
    $this->other = insertCategory('فئة-وقود');
    $at = fn (string $date) => CarbonImmutable::parse($date.' 10:00', 'UTC');
    $this->posts = [
        'gold' => insertPost(publishedPostValues(['title' => 'ارتفاع أسعار الذهب في نابلس', 'excerpt' => 'الذهب يقفز', 'category_id' => $this->other, 'published_at' => $at('2026-09-01')])),
        'company' => insertPost(publishedPostValues(['title' => 'الشَّرِكَةُ الوطنيَّة للكهرباء', 'category_id' => $this->child, 'published_at' => $at('2026-10-01')])),
        'shekel' => insertPost(publishedPostValues(['title' => 'سعر الشيكل أمام الدولار', 'category_id' => $this->parent, 'published_at' => $at('2026-10-10')])),
        'episode' => insertPost(publishedPostValues(['kind_code' => 'episode', 'title' => 'حلقة عن الذهب والادخار', 'category_id' => null, 'published_at' => $at('2026-10-15')])),
        'draft' => insertPost(['title' => 'مسودة عن الذهب', 'body' => '<p>نص</p>']),
    ];
});

afterEach(fn () => dropSearchIndexes());

function search(string $text, array $options = []): SearchResults
{
    return app(Searcher::class)->search(new SearchQuery($text, ...$options));
}

function ids(SearchResults $results): array
{
    return array_column($results->posts, 'id');
}

/** Runs the same expectations on the engine and on the PostgreSQL fallback. */
dataset('engines', [
    'meilisearch' => fn () => tap('meilisearch', function () {
        useSearchEngine();
        foreach (array_keys(SearchIndexer::indexes()) as $key) {
            SearchIndexer::make()->rebuild($key);
        }
    }),
    'database' => fn () => tap('database', fn () => config(['raqam.search.key' => ''])),
]);

test('hamza, ta marbuta, diacritics and the article do not stop a match', function (string $engine) {
    $p = $this->posts;

    expect(ids(search('اسعار الذهب')))->toBe([$p['gold']])
        ->and(ids(search('أسعار ذهب')))->toBe([$p['gold']])
        ->and(ids(search('شركة')))->toBe([$p['company']])
        ->and(ids(search('الشركه الوطنية')))->toBe([$p['company']])
        ->and(ids(search('الشيكل')))->toBe([$p['shekel']])
        ->and(search('ذهب')->engine)->toBe($engine === 'meilisearch' ? SearchResults::ENGINE : SearchResults::FALLBACK);
})->with('engines');

test('drafts never come back, whatever the query', function (string $engine) {
    expect(ids(search('مسودة')))->toBe([])
        ->and(ids(search('الذهب')))->not->toContain($this->posts['draft']);
})->with('engines');

test('filters: a main category takes its subcategories, and section, kind and period', function (string $engine) {
    $p = $this->posts;

    expect(ids(search('الشركة', ['categoryId' => $this->parent])))->toBe([$p['company']])
        ->and(ids(search('الشركة', ['categoryId' => $this->other])))->toBe([])
        ->and(ids(search('الذهب', ['sectionKey' => 'podcast'])))->toBe([$p['episode']])
        ->and(ids(search('الذهب', ['kind' => 'news'])))->toBe([$p['gold']])
        ->and(ids(search('الذهب', ['from' => CarbonImmutable::parse('2026-10-01')])))->toBe([$p['episode']])
        ->and(ids(search('الذهب', ['to' => CarbonImmutable::parse('2026-09-30 23:59:59')])))->toBe([$p['gold']]);
})->with('engines');

test('sorted by date, newest first, on request', function (string $engine) {
    expect(ids(search('الذهب', ['sort' => 'latest'])))->toBe([$this->posts['episode'], $this->posts['gold']]);
})->with('engines');

test('the synonyms of the admin are used', function (string $engine) {
    $group = DB::table('synonym_groups')->insertGetId([]);
    DB::table('synonym_terms')->insert([['term' => 'شيكل', 'group_id' => $group], ['term' => 'شيقل', 'group_id' => $group]]);
    $engine === 'meilisearch' && SearchIndexer::make()->pushSynonyms(wait: true);

    expect(ids(search('الشيقل')))->toBe([$this->posts['shekel']]);
})->with('engines');

test('writers, guests, tags and videos come with an unfiltered first page', function (string $engine) {
    $author = DB::table('authors')->insertGetId(['name' => 'سامر الذهبي', 'slug' => 'samer']);
    DB::table('post_authors')->insert(['post_id' => $this->posts['gold'], 'author_id' => $author]);
    $engine === 'meilisearch' && SearchIndexer::make()->rebuild('authors');

    $results = search('سامر');

    expect(array_column($results->groups['authors'], 'id'))->toBe([$author])
        ->and(search('سامر', ['kind' => 'news'])->groups)->toBe([])
        ->and(search('سامر', ['page' => 2])->groups)->toBe([]);
})->with('engines');

test('match positions point at the search form of the original title', function (string $engine) {
    $hit = search('الشركة')->posts[0];

    expect($hit['fields']['title_n'])->toBe('شركه وطنيه كهرباء')
        ->and(Highlighter::mark('الشَّرِكَةُ الوطنيَّة للكهرباء', $hit['matches']['title_n']))
        ->toBe('<mark>الشَّرِكَةُ</mark> الوطنيَّة للكهرباء');
})->with('engines');

// ---- engine only -------------------------------------------------------------------------------------

test('a typo is tolerated by the engine (10.3)', function () {
    useSearchEngine();
    SearchIndexer::make()->rebuild('posts');

    expect(ids(search('الكهرباع')))->toBe([$this->posts['company']]);
});

test('a stale document is not returned: every hit is checked against the database again', function () {
    $character = Character::create(['name_ar' => 'المستشار', 'slug' => 'advisor', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']);
    $pose = DB::table('character_poses')->insertGetId(['character_id' => $character->id, 'pose_type_code' => 'explaining', 'static_media_id' => insertMedia()]);
    $card = insertPost(publishedPostValues(['kind_code' => 'awareness', 'title' => 'نصيحة عن الذهب', 'category_id' => null]));
    DB::table('awareness_cards')->insert(['post_id' => $card, 'kind_code' => 'awareness', 'character_pose_id' => $pose, 'awareness_type_code' => 'advice']);
    useSearchEngine();
    SearchIndexer::make()->rebuild('posts');
    expect(ids(search('الذهب')))->toContain($card)->toContain($this->posts['gold']);

    // Both leave the site behind the index's back: no event, no job.
    DB::table('posts')->where('id', $this->posts['gold'])->update(['status' => 'draft']);
    DB::table('characters')->where('id', $character->id)->update(['is_active' => false]);

    expect(ids(search('الذهب')))->toBe([$this->posts['episode']]);
});

test('every engine search is pinned to Arabic, and filters carry no visitor text', function () {
    config(['raqam.search.key' => 'k', 'raqam.search.prefix' => 'p_']);
    Http::fake(['*/multi-search' => Http::response(['results' => array_fill(0, 5, ['hits' => [], 'totalHits' => 0])])]);

    search('الذهب');
    search('الذهب', ['kind' => 'news', 'categoryId' => 5, 'sectionKey' => 'podcast', 'from' => CarbonImmutable::parse('2026-01-01')]);

    Http::assertSent(function ($request) {
        foreach ($request['queries'] as $query) {
            expect($query['locales'])->toBe(['ara'])->and($query['q'])->toBe('ذهب');
        }

        return true;
    });
    Http::assertSent(fn ($request) => ($request['queries'][0]['filter'] ?? []) === ['categories = 5', 'section = "podcast"', 'kind = "news"', 'published_at >= '.CarbonImmutable::parse('2026-01-01')->getTimestamp()]);
});

test('a filter key that is not a plain key is refused before it reaches the engine', function () {
    config(['raqam.search.key' => 'k']);
    Http::fake();

    expect(fn () => search('الذهب', ['kind' => 'news" OR id > 0']))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

// ---- the fallback (10.11) ----------------------------------------------------------------------------

test('when the engine does not answer, the database answers, and the engine is left alone for a while', function () {
    config(['raqam.search.key' => 'k', 'raqam.search.down_seconds' => 30]);
    Http::swap(new Factory);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('down');
    });

    $first = search('الذهب');
    $second = search('الشيكل');

    expect($first->engine)->toBe(SearchResults::FALLBACK)
        ->and(ids($first))->toBe([$this->posts['episode'], $this->posts['gold']])
        ->and(ids($second))->toBe([$this->posts['shekel']])
        ->and($calls)->toBe(1)
        ->and(Searcher::isDown())->toBeTrue();

    $this->travel(31)->seconds();
    expect(Searcher::isDown())->toBeFalse();
});

test('an engine error that is not a timeout also falls back', function () {
    config(['raqam.search.key' => 'k']);
    Http::fake(['*' => Http::response(['message' => 'boom', 'code' => 'internal'], 500)]);

    expect(search('الذهب')->engine)->toBe(SearchResults::FALLBACK);
});

test('with the engine switched off, nothing calls it', function () {
    config(['raqam.search.key' => '']);
    Http::fake();

    expect(search('الذهب')->engine)->toBe(SearchResults::FALLBACK)
        ->and(MeiliClient::configured())->toBeFalse();
    Http::assertNothingSent();
});

test('the fallback treats LIKE wildcards as plain text', function () {
    config(['raqam.search.key' => '']);

    // Only letters and digits make words: % and _ never reach the LIKE pattern as wildcards.
    expect(ids(search('%')))->toBe([])->and(ids(search('_')))->toBe([])
        ->and(ids(search('ذهب_%')))->toBe(ids(search('ذهب')));
});
