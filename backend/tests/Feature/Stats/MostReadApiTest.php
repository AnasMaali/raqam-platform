<?php

use App\Analytics\StatsFlusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function readPost(string $title, int ...$viewsByDaysAgo): int
{
    $id = insertPost(publishedPostValues(['title' => $title, 'slug' => 'p-'.bin2hex(random_bytes(3)), 'published_at' => now()->subDays(10)]));
    foreach ($viewsByDaysAgo as $daysAgo => $views) {
        if ($views > 0) {
            DB::table('post_views_daily')->insert(['post_id' => $id, 'stat_date' => now('Asia/Hebron')->subDays($daysAgo)->toDateString(), 'views' => $views]);
        }
    }

    return $id;
}

function showViewCounts(bool $on): void
{
    DB::table('feature_flags')->where('key', 'public_view_counts')->update(['is_enabled' => $on]);
}

test('the most read today, most first, live posts only, in the contract shape', function () {
    $a = readPost('أ', 5);
    $b = readPost('ب', 9);
    $c = readPost('ج', 0, 100);   // read only yesterday
    $draft = readPost('مسودة', 50);
    DB::table('posts')->where('id', $draft)->update(['status' => 'draft']);

    $json = api('/posts/most-read')->assertOk()->json();

    expect(array_column($json['data'], 'id'))->toBe([$b, $a])
        ->and($json['meta'])->toBe(['period' => 'day', 'from' => now('Asia/Hebron')->toDateString(), 'to' => now('Asia/Hebron')->toDateString()])
        ->and($c)->toBeInt();
    validateAgainstPath($json, '/posts/most-read');
});

test('the week sums the last seven days, today included', function () {
    $a = readPost('أ', 1, 1, 1, 1, 1, 1, 1, 50);   // 7 within the week, 50 eight days ago
    $b = readPost('ب', 0, 0, 0, 0, 0, 0, 6);
    $c = readPost('ج', 8);

    $json = api('/posts/most-read?period=week&limit=2')->assertOk()->json();

    expect(array_column($json['data'], 'id'))->toBe([$c, $a])
        ->and($json['meta']['from'])->toBe(now('Asia/Hebron')->subDays(6)->toDateString())->and($b)->toBeInt();
});

test('just after midnight "today" is yesterday, and the answer says so', function () {
    $a = readPost('أ', 0, 3);

    $json = api('/posts/most-read?period=day')->assertOk()->json();

    expect(array_column($json['data'], 'id'))->toBe([$a])
        ->and($json['meta']['from'])->toBe(now('Asia/Hebron')->subDay()->toDateString());
});

test('a post unpublished after the ranking was kept leaves the list at once', function () {
    $a = readPost('أ', 5);
    $b = readPost('ب', 3);
    api('/posts/most-read')->assertOk();
    DB::table('posts')->where('id', $a)->update(['status' => 'draft']);

    expect(array_column(api('/posts/most-read')->json('data'), 'id'))->toBe([$b]);
});

test('the ranking is kept until the next transfer', function () {
    $a = readPost('أ', 5);
    $b = readPost('ب', 3);
    api('/posts/most-read')->assertOk();
    DB::table('post_views_daily')->where('post_id', $b)->update(['views' => 30]);

    expect(array_column(api('/posts/most-read')->json('data'), 'id'))->toBe([$a, $b]);
    Cache::forever(StatsFlusher::VERSION, (int) Cache::get(StatsFlusher::VERSION, 0) + 1);
    expect(array_column(api('/posts/most-read')->json('data'), 'id'))->toBe([$b, $a]);
});

test('the query is checked', function (string $query) {
    api('/posts/most-read?'.$query)->assertStatus(422);
})->with(['period=month', 'limit=0', 'limit=13', 'limit=x']);

test('view counts stay hidden from visitors unless the admin shows them (22.12)', function () {
    $a = readPost('أ', 5, 4);

    $list = api('/posts/most-read')->json('data.0');
    $detail = api("/posts/{$a}")->json('data');
    $posts = api('/posts')->json('data.0');
    expect($list)->not->toHaveKey('views')->not->toHaveKey('period_views')
        ->and($detail)->not->toHaveKey('views')->and($posts)->not->toHaveKey('views');

    showViewCounts(true);
    DB::statement('REFRESH MATERIALIZED VIEW post_view_totals');

    expect(api('/posts/most-read')->json('data.0'))->toMatchArray(['views' => 9, 'period_views' => 5])
        ->and(api("/posts/{$a}")->json('data.views'))->toBe(9)
        ->and(api('/posts')->json('data.0.views'))->toBe(9);
    validateAgainstPath(api('/posts/most-read')->json(), '/posts/most-read');
    matchesContract(api("/posts/{$a}")->json('data'), 'PostSummary');
});

test('a post nobody read yet shows 0 when counts are shown', function () {
    showViewCounts(true);
    $id = readPost('جديد');

    expect(api("/posts/{$id}")->json('data.views'))->toBe(0);
});
