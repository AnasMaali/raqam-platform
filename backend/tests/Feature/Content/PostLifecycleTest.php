<?php

use App\Content\BodyRenderer;
use App\Content\PostPreview;
use App\Content\PostPublisher;
use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Events\PostChanged;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\PublishScheduledPost;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->saver = app(PostSaver::class);
    Bus::fake([GenerateMediaVariants::class]);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->data = ['title' => 'خبر', 'body' => '<p>نص</p>', 'category_id' => Category::value('id'), 'cover_media_id' => $this->cover->id];
});

function scheduled(?Carbon\Carbon $at = null): Post
{
    $post = app(PostSaver::class)->create('news', 'مجدول', auth()->id());

    return app(PostSaver::class)->save($post, [...test()->data, 'status' => 'scheduled', 'published_at' => $at ?? now()->addHour()], auth()->id());
}

// ---- scheduling ----------------------------------------------------------------------------------

test('saving a scheduled item queues a job for its exact time', function () {
    Bus::fake([PublishScheduledPost::class]);
    $at = now()->addHours(3);

    $post = scheduled($at);

    Bus::assertDispatched(PublishScheduledPost::class, fn ($job) => $job->postId === $post->id && abs($job->delay->diffInSeconds($at, false)) < 1);
});

test('an item goes live when its time comes, and not before', function () {
    $post = scheduled(now()->addHour());
    $publisher = app(PostPublisher::class);

    expect($publisher->publishIfDue($post->id))->toBeFalse()->and($post->fresh()->status)->toBe('scheduled');

    $this->travel(61)->minutes();

    expect($publisher->publishIfDue($post->id))->toBeTrue()->and($post->fresh()->status)->toBe('published');
    expect($publisher->publishIfDue($post->id))->toBeFalse();   // already done: nothing happens twice
});

test('the every-minute task puts every due item live and leaves the rest', function () {
    $due = scheduled(now()->addMinutes(10));
    $later = scheduled(now()->addDays(2));
    $draft = app(PostSaver::class)->create('news', 'مسودة', $this->user->id);
    $this->travel(11)->minutes();

    $this->artisan('posts:publish-due')->expectsOutput('Published 1 post(s).')->assertSuccessful();

    expect($due->fresh()->status)->toBe('published')->and($later->fresh()->status)->toBe('scheduled')->and($draft->fresh()->status)->toBe('draft');
});

test('an item that was pulled back, moved or deleted before its time is left alone', function () {
    $pulled = scheduled(now()->addMinutes(10));
    $this->saver->save($pulled, [...$this->data, 'status' => 'draft'], $this->user->id);

    $moved = scheduled(now()->addMinutes(10));
    $this->saver->save($moved->fresh(), [...$this->data, 'status' => 'scheduled', 'published_at' => now()->addDays(3)], $this->user->id);

    $deleted = scheduled(now()->addMinutes(10));
    $this->saver->trash($deleted->fresh());

    $this->travel(11)->minutes();
    (new PublishScheduledPost($pulled->id))->handle(app(PostPublisher::class));
    (new PublishScheduledPost($moved->id))->handle(app(PostPublisher::class));
    (new PublishScheduledPost($deleted->id))->handle(app(PostPublisher::class));

    expect($pulled->fresh()->status)->toBe('draft')->and($moved->fresh()->status)->toBe('scheduled')
        ->and(Post::withTrashed()->find($deleted->id)->status)->toBe('scheduled');
});

test('going live is announced and audited as a publication', function () {
    $post = scheduled(now()->addMinutes(5));
    $this->travel(6)->minutes();
    Event::fake([PostChanged::class]);

    app(PostPublisher::class)->publishIfDue($post->id);

    Event::assertDispatched(PostChanged::class, fn ($e) => $e->postId === $post->id);
    expect(DB::table('audit_log')->where('subject_id', $post->id)->where('action', 'publish')->exists())->toBeTrue();
});

test('the schedule runs the safety net every minute and the trash clean-up daily', function () {
    $expression = fn (string $command) => collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, $command))?->expression;

    expect($expression('posts:publish-due'))->toBe('* * * * *')->and($expression('posts:purge-trashed'))->toBe('0 0 * * *');
});

test('a scheduled item saved again as it comes due is not refused for having a time in the past', function () {
    $post = scheduled(now()->addMinutes(5));
    $this->travel(6)->minutes();

    $this->saver->save($post->fresh(), [...$this->data, 'title' => 'عنوان معدّل', 'status' => 'scheduled', 'published_at' => $post->fresh()->published_at], $this->user->id);

    expect($post->fresh()->title)->toBe('عنوان معدّل');
});

// ---- trash ---------------------------------------------------------------------------------------

test('items older than 90 days in the trash are deleted for good, younger ones are kept', function () {
    $old = app(PostSaver::class)->create('news', 'قديم', $this->user->id);
    $recent = app(PostSaver::class)->create('news', 'حديث', $this->user->id);
    $this->saver->trash($old);
    DB::table('posts')->where('id', $old->id)->update(['deleted_at' => now()->subDays(Post::TRASH_DAYS + 1)]);
    DB::table('posts')->where('id', $old->id)->update(['deleted_at' => now()->subDays(Post::TRASH_DAYS + 1)]);
    $this->saver->trash($recent);
    DB::table('posts')->where('id', $recent->id)->update(['deleted_at' => now()->subDays(Post::TRASH_DAYS - 1)]);

    $this->artisan('posts:purge-trashed')->expectsOutput('Deleted 1 post(s) for good.')->assertSuccessful();

    expect(Post::withTrashed()->find($old->id))->toBeNull()->and(Post::withTrashed()->find($recent->id))->not->toBeNull();
    expect(DB::table('redirects')->where('from_path', "/news/{$old->id}")->value('status_code'))->toBe(410);
});

test('purging removes the post with everything that hangs off it, and frees its pictures', function () {
    $post = app(PostSaver::class)->create('news', 'عنوان', $this->user->id);
    $this->saver->save($post, [...$this->data, 'gallery' => [$this->cover->id]], $this->user->id);
    $this->saver->trash($post->fresh());

    $this->saver->purge(Post::withTrashed()->find($post->id));

    foreach (['post_revisions', 'post_gallery_items', 'post_authors', 'post_tags', 'post_body_media'] as $table) {
        expect(DB::table($table)->where('post_id', $post->id)->count())->toBe(0);
    }
    app(MediaTrash::class)->trash($this->cover);   // no longer used by anything
    expect($this->cover->fresh()->trashed())->toBeTrue();
});

// ---- revisions -----------------------------------------------------------------------------------

test('an earlier revision can be put back, keeping the current text as a revision first', function () {
    $post = app(PostSaver::class)->create('news', 'عنوان أول', $this->user->id);
    $this->saver->save($post, [...$this->data, 'title' => 'عنوان أول', 'body' => '<p>النص الأول</p>'], $this->user->id);
    $this->saver->save($post->fresh(), [...$this->data, 'title' => 'عنوان ثان', 'body' => '<p>النص الثاني</p>', 'is_featured' => true], $this->user->id);

    $first = DB::table('post_revisions')->where('post_id', $post->id)->where('body', '<p>النص الأول</p>')->value('revision_no');
    $this->saver->restoreRevision($post->fresh(), $first, $this->user->id);

    expect($post->fresh())->title->toBe('عنوان أول')->body->toBe('<p>النص الأول</p>')->is_featured->toBeTrue();
    expect(DB::table('post_revisions')->where('post_id', $post->id)->orderByDesc('revision_no')->pluck('body')->all())
        ->toBe(['<p>النص الأول</p>', '<p>النص الثاني</p>', '<p>النص الأول</p>', '']);
});

test('restoring a revision of a published item keeps it published, with the same address and time', function () {
    $post = app(PostSaver::class)->create('news', 'عنوان', $this->user->id);
    $this->saver->save($post, [...$this->data, 'status' => 'published', 'body' => '<p>أول</p>'], $this->user->id);
    $before = $post->fresh();
    $this->saver->save($post->fresh(), [...$this->data, 'status' => 'published', 'body' => '<p>ثان</p>'], $this->user->id);
    $first = DB::table('post_revisions')->where('post_id', $post->id)->where('body', '<p>أول</p>')->value('revision_no');

    $this->saver->restoreRevision($post->fresh(), $first, $this->user->id);

    expect($post->fresh())->status->toBe('published')->slug->toBe($before->slug)->body->toBe('<p>أول</p>')
        ->and($post->fresh()->published_at->equalTo($before->published_at))->toBeTrue();
    expect(DB::table('redirects')->count())->toBe(0);
});

test('a revision of another post or one that does not exist cannot be restored', function () {
    $a = app(PostSaver::class)->create('news', 'أ', $this->user->id);
    $b = app(PostSaver::class)->create('news', 'ب', $this->user->id);

    expect(fn () => $this->saver->restoreRevision($a, 99, $this->user->id))->toThrow(PostRuleViolation::class);
    expect(fn () => $this->saver->restoreRevision($a, 1, $this->user->id))->not->toThrow(Throwable::class);
    expect($a->fresh()->title)->toBe('أ')->and($b->fresh()->title)->toBe('ب');
});

test('a restored text is cleaned again, so an old revision cannot bring back what is no longer allowed', function () {
    $post = app(PostSaver::class)->create('news', 'عنوان', $this->user->id);
    DB::table('post_revisions')->insert(['post_id' => $post->id, 'revision_no' => 5, 'title' => 'عنوان', 'body' => '<p>نص</p><script>alert(1)</script>']);

    $this->saver->restoreRevision($post, 5, $this->user->id);

    expect($post->fresh()->body)->toBe('<p>نص</p>');
});

// ---- preview -------------------------------------------------------------------------------------

test('a preview link shows an unpublished item to anyone who holds it', function () {
    $post = app(PostSaver::class)->create('news', 'خبر سري', $this->user->id);
    $this->saver->save($post, [...$this->data, 'title' => 'خبر سري', 'body' => '<p>محتوى لم يُنشر</p>'], $this->user->id);
    auth()->logout();

    $url = app(PostPreview::class)->url($post->fresh(), 48);
    $response = $this->get($url)->assertOk()->assertSee('خبر سري')->assertSee('محتوى لم يُنشر')->assertSee('معاينة خاصة');

    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    $response->assertSee('width=device-width', false)->assertSee('noindex', false);
});

test('a preview link stops working after its time, and cannot be changed or made up', function () {
    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);
    $url = app(PostPreview::class)->url($post, 24);
    auth()->logout();

    $this->get($url)->assertOk();
    $other = app(PostSaver::class)->create('news', 'آخر', $this->user->id);
    $this->get(str_replace("/preview/{$post->id}", "/preview/{$other->id}", $url))->assertForbidden();
    $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->assertForbidden();
    $this->get("/preview/{$post->id}")->assertForbidden();
    $this->get("/preview/{$post->id}?signature=abc&expires=9999999999")->assertForbidden();

    $this->travel(25)->hours();
    $this->get($url)->assertForbidden();
});

test('only the lifetimes on offer can be asked for', function () {
    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);

    expect(fn () => app(PostPreview::class)->url($post, 100000))->toThrow(InvalidArgumentException::class);
});

test('an item in the trash cannot be previewed, and the page is not a way to see other tables', function () {
    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);
    $url = app(PostPreview::class)->url($post);
    $this->saver->trash($post->fresh());
    auth()->logout();

    $this->get($url)->assertNotFound();
    $this->get('/preview/abc')->assertNotFound();
});

test('a preview shows the text as the site will: blocks built from the database and no markup of other origin', function () {
    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);
    $body = '<p>مقدمة</p><div data-type="customBlock" data-id="image" data-config=\'{"media_id":'.$this->cover->id.',"caption":"لقطة"}\'></div>';
    $this->saver->save($post, [...$this->data, 'title' => 'خبر', 'body' => $body], $this->user->id);
    auth()->logout();

    $this->get(app(PostPreview::class)->url($post->fresh()))->assertOk()
        ->assertSee('<figure class="rq-image">', false)->assertSee('لقطة')->assertDontSee('customBlock', false)->assertDontSee('<script', false);
});

test('the renderer leaves ordinary text alone and drops a block whose target is gone', function () {
    $renderer = app(BodyRenderer::class);

    expect($renderer->html('<p>نص عادي</p>'))->toBe('<p>نص عادي</p>');
    expect($renderer->html('<p>أ</p><div data-type="customBlock" data-id="image" data-config=\'{"media_id":99999}\'></div><p>ب</p>'))->toBe('<p>أ</p><p>ب</p>');
});

test('the preview text is escaped: a title with markup is shown as text', function () {
    $post = app(PostSaver::class)->create('news', 'عنوان <script>alert(1)</script>', $this->user->id);
    $url = app(PostPreview::class)->url($post);
    auth()->logout();

    $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});
