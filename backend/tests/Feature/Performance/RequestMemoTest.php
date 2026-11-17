<?php

use App\Api\Presenter;
use App\Models\Post;
use App\Models\PostKind;
use App\Support\RequestMemo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * What one answer reads once is kept on the request (QA-5). A queue worker is one process for hours, with one request
 * object for every job it runs: what the first job kept must not reach the next. Here two jobs run one after the other
 * in this process (the sync queue raises the same events as the worker), with a change made between them.
 */

uses(RefreshDatabase::class);

/** Reads what the request keeps: the view-count switch (through the presenter) and a kind's name. */
final class ReadsKeptValuesJob implements ShouldQueue
{
    use Dispatchable;

    /** @var list<array{views: bool, kind: string}> */
    public static array $seen = [];

    public function __construct(private int $post) {}

    public function handle(Presenter $presenter): void
    {
        $summary = $presenter->summaries(Post::query()->whereKey($this->post)->get())[0];
        self::$seen[] = ['views' => array_key_exists('views', $summary), 'kind' => PostKind::names()['news']];
    }
}

test('a job sees the switch and the names as they are when it runs, not as an earlier job of the same worker read them', function () {
    ensurePostKinds();
    $post = insertPost(publishedPostValues());
    DB::table('feature_flags')->updateOrInsert(['key' => 'public_view_counts'], ['is_enabled' => false]);
    ReadsKeptValuesJob::$seen = [];

    ReadsKeptValuesJob::dispatch($post);
    DB::table('feature_flags')->where('key', 'public_view_counts')->update(['is_enabled' => true]);
    DB::table('post_kinds')->where('code', 'news')->update(['name_ar' => 'خبر عاجل']);
    ReadsKeptValuesJob::dispatch($post);

    expect(ReadsKeptValuesJob::$seen)->toBe([['views' => false, 'kind' => 'خبر'], ['views' => true, 'kind' => 'خبر عاجل']]);
});

test('emptying the memo before a job leaves the request\'s other values alone (a sync job inside a web request)', function () {
    request()->attributes->set('admin.logout_reason', 'idle');
    RequestMemo::put('kinds', ['news' => 'خبر']);

    RequestMemo::flush();

    expect(request()->attributes->get('admin.logout_reason'))->toBe('idle')
        ->and(RequestMemo::has('kinds'))->toBeFalse();
});
