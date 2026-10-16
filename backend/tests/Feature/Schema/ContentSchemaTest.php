<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/06-content.sql, ported from database/tests/schema_tests.sql.

// The subtype row each section kind needs before it can leave draft.
function subtypeRow(string $kind, int $post): array
{
    $organization = fn () => DB::table('organizations')->insertGetId(['name' => 'شركة', 'slug' => "org-{$post}"]);

    return match ($kind) {
        'episode' => ['podcast_episodes', ['post_id' => $post, 'episode_number' => 1,
            'season_id' => DB::table('podcast_seasons')->insertGetId(['season_number' => $post])]],
        'story' => ['success_stories', ['post_id' => $post, 'organization_id' => $organization()]],
        'product' => ['products', ['post_id' => $post, 'product_name' => 'صابون', 'organization_id' => $organization()]],
        'history' => ['history_entries', ['post_id' => $post,
            'era_id' => DB::table('eras')->insertGetId(['name_ar' => "حقبة {$post}"])]],
        'initiative' => ['initiatives', ['post_id' => $post, 'initiative_name' => 'مبادرة', 'organizer_organization_id' => $organization()]],
        'roundtable' => ['roundtable_sessions', ['post_id' => $post, 'held_on' => '2026-10-01']],
        'awareness' => ['awareness_cards', ['post_id' => $post, 'character_pose_id' => insertPose(), 'awareness_type_code' => insertAwarenessType()]],
    };
}

function insertPose(): int
{
    ensure('pose_types', ['code' => 'explaining', 'name_ar' => 'تشرح', 'sort_order' => 1]);
    $character = DB::table('characters')->insertGetId(['name_ar' => 'رَقَم', 'slug' => 'raqam', 'role_ar' => 'الراوي', 'accent_color' => '#0B3D5C']);

    return DB::table('character_poses')->insertGetId([
        'character_id' => $character, 'pose_type_code' => 'explaining', 'static_media_id' => insertMedia(['mime_type' => 'image/png']),
    ]);
}

function insertAwarenessType(): string
{
    ensure('awareness_types', ['code' => 'did_you_know', 'name_ar' => 'هل تعلم؟', 'sort_order' => 1]);

    return 'did_you_know';
}

// What COMMIT checks for the deferred constraint trigger.
function checkDeferredSubtype(): void
{
    DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');
}

dataset('section kinds', [
    'episode' => ['episode', 'podcast_episodes'],
    'story' => ['story', 'success_stories'],
    'product' => ['product', 'products'],
    'history' => ['history', 'history_entries'],
    'initiative' => ['initiative', 'initiatives'],
    'roundtable' => ['roundtable', 'roundtable_sessions'],
    'awareness' => ['awareness', 'awareness_cards'],
]);

test('a news post needs a category before it leaves draft', function () {
    expect(rejected(fn () => insertPost(publishedPostValues(['category_id' => null]))))
        ->toThrow(QueryException::class, 'Kind "news" needs a category before it can leave draft');
});

test('a published post needs its publication time', function () {
    expect(rejected(fn () => insertPost(publishedPostValues(['published_at' => null]))))
        ->toThrow(QueryException::class, '"posts_check"');
});

test('a published post needs its main image', function () {
    expect(rejected(fn () => insertPost(publishedPostValues(['cover_media_id' => null]))))
        ->toThrow(QueryException::class, '"posts_check1"');
});

test('a published post needs its text', function () {
    expect(rejected(fn () => insertPost(publishedPostValues(['body' => '   ']))))
        ->toThrow(QueryException::class, '"posts_check2"');
});

test('a correction note comes with its correction time', function () {
    expect(rejected(fn () => insertPost(['correction_note' => 'تصحيح الرقم'])))
        ->toThrow(QueryException::class, '"posts_check3"');
});

test('breaking news ends after it is published', function () {
    expect(rejected(fn () => insertPost(publishedPostValues(['breaking_until' => now()->subHour()]))))
        ->toThrow(QueryException::class, '"posts_check4"');
});

test('the kind of a post cannot change', function () {
    $post = insertPost(publishedPostValues());

    expect(rejected(fn () => DB::table('posts')->where('id', $post)->update(['kind_code' => 'report'])))
        ->toThrow(QueryException::class, 'The kind of a post cannot change after creation');
});

test('a kind without a section must require a category', function () {
    expect(rejected(fn () => DB::table('post_kinds')->insert([
        'code' => 'brief', 'name_ar' => 'موجز', 'requires_category' => false, 'schema_org_type' => 'NewsArticle',
    ])))->toThrow(QueryException::class, '"post_kinds_check"');
});

test('a subtype row cannot attach to a post of another kind', function () {
    $news = insertPost(publishedPostValues());
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 99]);

    expect(rejected(fn () => DB::table('podcast_episodes')->insert(['post_id' => $news, 'season_id' => $season, 'episode_number' => 1])))
        ->toThrow(QueryException::class, '"podcast_episodes_post_id_kind_code_fkey"');
});

test('a section post cannot be published without its subtype row', function (string $kind, string $table) {
    expect(rejected(function () use ($kind) {
        insertPost(publishedPostValues(['kind_code' => $kind, 'category_id' => null]));
        checkDeferredSubtype();
    }))->toThrow(QueryException::class, "(kind {$kind}) needs its {$table} row before leaving draft");
})->with('section kinds');

test('a section post and its subtype row can be saved in one transaction', function (string $kind, string $table) {
    $post = insertPost(publishedPostValues(['kind_code' => $kind, 'category_id' => null]));
    [$subtypeTable, $row] = subtypeRow($kind, $post);
    DB::table($subtypeTable)->insert($row);
    checkDeferredSubtype();

    expect($subtypeTable)->toBe($table)
        ->and(DB::table('posts')->where('id', $post)->value('status'))->toBe('published');
})->with('section kinds');

test('a draft section post needs no subtype row yet', function () {
    insertPost(['kind_code' => 'episode', 'title' => 'حلقة قادمة', 'slug' => 'حلقة-قادمة']);
    checkDeferredSubtype();

    expect(DB::table('posts')->where('slug', 'حلقة-قادمة')->exists())->toBeTrue();
});

test('an episode number is unique within its season', function () {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $first = insertPost(['kind_code' => 'episode']);
    $second = insertPost(['kind_code' => 'episode']);
    DB::table('podcast_episodes')->insert(['post_id' => $first, 'season_id' => $season, 'episode_number' => 1]);

    expect(rejected(fn () => DB::table('podcast_episodes')->insert(['post_id' => $second, 'season_id' => $season, 'episode_number' => 1])))
        ->toThrow(QueryException::class, '"podcast_episodes_season_id_episode_number_key"');
});

test('a history entry cannot end before it starts', function () {
    $post = insertPost(['kind_code' => 'history']);
    $era = DB::table('eras')->insertGetId(['name_ar' => 'العهد العثماني']);

    expect(rejected(fn () => DB::table('history_entries')->insert(['post_id' => $post, 'era_id' => $era, 'year_from' => 1900, 'year_to' => 1850])))
        ->toThrow(QueryException::class, '"history_entries_check"');
});

test('a post cannot be related to itself', function () {
    $post = insertPost();

    expect(rejected(fn () => DB::table('post_related')->insert(['post_id' => $post, 'related_post_id' => $post])))
        ->toThrow(QueryException::class, '"post_related_check"');
});

test('an image used inside an article body cannot be deleted', function () {
    $media = insertMedia();
    DB::table('post_body_media')->insert(['post_id' => insertPost(), 'media_id' => $media]);

    expect(rejected(fn () => DB::table('media_assets')->delete($media)))
        ->toThrow(QueryException::class, '"post_body_media_media_id_fkey"');
});

test('video_usage lists the main video and body embeds of live posts', function () {
    $main = insertYoutubeVideo('dQw4w9WgXcQ');
    $embed = insertYoutubeVideo('abcdefghijk');
    $trashed = insertYoutubeVideo('ZYXWVUTSRQP');
    $post = insertPost(['primary_video_id' => $main]);
    DB::table('post_body_videos')->insert(['post_id' => $post, 'video_id' => $embed]);
    DB::table('post_body_videos')->insert(['post_id' => insertPost(['deleted_at' => now()]), 'video_id' => $trashed]);

    $usage = DB::table('video_usage')->orderBy('usage')->get(['video_id', 'usage'])
        ->map(fn ($row) => [$row->video_id, $row->usage])->all();

    expect($usage)->toBe([[$embed, 'body'], [$main, 'primary']]);
});

test('the fallback search ignores hamza and tolerates a typo', function (string $query, string $operator) {
    insertPost(publishedPostValues(['title' => 'إرتفاع أسعار الدولار في نابلس', 'slug' => 'ارتفاع-الدولار']));

    $match = $operator === 'like'
        ? "search_text LIKE '%' || normalize_ar(?) || '%'"
        : 'word_similarity(normalize_ar(?), search_text) > 0.5';

    expect(DB::table('posts')->where('status', 'published')->whereRaw($match, [$query])->count())->toBe(1);
})->with([
    'hamza written differently' => ['ارتفاع اسعار', 'like'],
    'missing letter (trigram)' => ['الدولر', 'similarity'],
]);

test('posts.search_text covers title, subtitle and excerpt', function () {
    $post = insertPost(['title' => 'أسعار الذهب', 'subtitle' => 'ارتفاع جديد', 'excerpt' => 'في نابلس']);

    expect(DB::table('posts')->find($post)->search_text)->toBe('اسعار الذهب ارتفاع جديد في نابلس');
});
