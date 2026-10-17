<?php

use App\Models\Post;
use App\Search\Highlighter;
use App\Search\MeiliClient;
use App\Search\PostDocument;
use App\Search\PostIndexer;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// These tests talk to a real Meilisearch (the key comes from the local .env, which CI does not have).
function meiliEnv(): array
{
    $env = is_file(base_path('.env')) ? Dotenv\Dotenv::parse(file_get_contents(base_path('.env'))) : [];

    return ['host' => $env['MEILISEARCH_HOST'] ?? 'http://127.0.0.1:7700', 'key' => $env['MEILISEARCH_KEY'] ?? ''];
}

function meiliForTests(): ?MeiliClient
{
    ['host' => $host, 'key' => $key] = meiliEnv();
    $client = $key === '' ? null : new MeiliClient($host, $key);

    return $client?->isReachable() ? $client : null;
}

beforeEach(function () {
    $this->meili = meiliForTests() ?? $this->markTestSkipped('Meilisearch is not running.');
    $this->index = 'test_posts_'.uniqid();
    config([
        'raqam.search.host' => meiliEnv()['host'],
        'raqam.search.key' => meiliEnv()['key'],
        'raqam.search.posts_index' => $this->index,
    ]);
    PostIndexer::fromConfig()->prepare();
});

afterEach(function () {
    if (isset($this->meili, $this->index)) {
        $this->meili->deleteIndex($this->index);
    }
});

function newPost(array $overrides = []): Post
{
    static $n = 0;
    $n++;
    $category = DB::table('categories')->insertGetId(['name_ar' => 'قسم', 'slug' => "cat-{$n}"]);

    $post = new Post;
    $post->forceFill([
        'kind_code' => 'news', 'category_id' => $category, 'title' => "خبر {$n}", 'slug' => "khabar-{$n}",
        'body' => '<p>نص</p>', 'cover_media_id' => insertMedia(), 'status' => 'published',
        'published_at' => now()->subHour(), ...$overrides,
    ])->save();

    return $post;
}

function indexed(): array
{
    test()->meili->settle(test()->index);
    $ids = test()->meili->documentIds(test()->index);
    sort($ids);

    return $ids;
}

it('indexes a published post', function () {
    $post = newPost();

    expect(indexed())->toBe([$post->id]);
});

it('never indexes a draft, a scheduled, an archived or a future-dated post', function () {
    newPost(['status' => 'draft', 'published_at' => null, 'cover_media_id' => null, 'body' => '']);
    newPost(['status' => 'scheduled', 'published_at' => now()->addDay()]);
    newPost(['status' => 'archived']);
    newPost(['status' => 'published', 'published_at' => now()->addDay()]);

    expect(indexed())->toBe([]);
});

it('removes a post that goes back to draft, and indexes it again when republished', function () {
    $post = newPost();
    expect(indexed())->toBe([$post->id]);

    $post->forceFill(['status' => 'draft'])->save();
    expect(indexed())->toBe([]);

    $post->forceFill(['status' => 'published'])->save();
    expect(indexed())->toBe([$post->id]);
});

it('removes a trashed post, brings it back on restore, and drops it on force delete', function () {
    $post = newPost();

    $post->delete();
    expect(indexed())->toBe([]);

    $post->restore();
    expect(indexed())->toBe([$post->id]);

    $post->forceDelete();
    expect(indexed())->toBe([]);
});

it('does not index a post whose transaction rolled back', function () {
    try {
        DB::transaction(function () {
            newPost();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(indexed())->toBe([]);
});

it('rebuild drops stale documents and leaves out everything that is not live', function () {
    $live = newPost();
    $this->meili->addDocuments($this->index, [['id' => 999999, 'title_n' => 'قديم']]);   // a document the database does not know
    newPost(['status' => 'draft', 'published_at' => null, 'cover_media_id' => null, 'body' => '']);

    expect(PostIndexer::fromConfig()->rebuild())->toBe(1)
        ->and(indexed())->toBe([$live->id]);
});

it('searches the normalised copy and highlights the original text', function () {
    $post = newPost(['title' => 'إنتاج الزيتون هذا الموسم', 'excerpt' => 'اقْتِصَادُ فِلَسْطِينَ']);
    indexed();

    $hit = $this->meili->search($this->index, ArabicNormalizer::normalize('انتاج'), [
        'showMatchesPosition' => true, 'attributesToRetrieve' => ['id', 'title', 'excerpt'], 'locales' => PostDocument::LOCALES,
    ])['hits'][0];

    expect($hit['id'])->toBe($post->id)
        ->and($hit['title'])->toBe('إنتاج الزيتون هذا الموسم')                       // the original, not the normalised copy
        ->and(Highlighter::mark($hit['title'], $hit['_matchesPosition']['title_n']))
        ->toBe('<mark>إنتاج</mark> الزيتون هذا الموسم');

    $hit = $this->meili->search($this->index, ArabicNormalizer::normalize('إقتصاد'), [
        'showMatchesPosition' => true, 'attributesToRetrieve' => ['id', 'excerpt'], 'locales' => PostDocument::LOCALES,
    ])['hits'][0];

    expect(Highlighter::mark($hit['excerpt'], $hit['_matchesPosition']['excerpt_n']))->toBe('<mark>اقْتِصَادُ</mark> فِلَسْطِينَ');
});
