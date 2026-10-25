<?php

use App\Content\PostSaver;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\PublishScheduledPost;
use App\Jobs\SubmitIndexNow;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Seo\IndexNow;
use App\Support\ProductionChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const INDEXNOW_TEST_KEY = 'a1b2c3d4e5f60718';

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class, PublishScheduledPost::class]);
    Cache::flush();
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'x'], null)->asset;
    $this->data = ['title' => 'خبر الطاقة', 'body' => '<p>نص</p>', 'category_id' => Category::value('id'), 'cover_media_id' => $this->cover->id];
    config(['raqam.site_url' => 'https://raqam.example', 'raqam.indexnow.key' => INDEXNOW_TEST_KEY]);
});

/** @return list<list<string>> the address lists queued for IndexNow so far */
function indexNowQueued(): array
{
    $sent = [];
    Bus::assertDispatched(SubmitIndexNow::class, function (SubmitIndexNow $job) use (&$sent) {
        $sent[] = $job->paths;

        return true;
    });

    return $sent;
}

function publishedNews(string $title = 'خبر الطاقة'): Post
{
    $t = test();
    $saver = app(PostSaver::class);

    return $saver->save($saver->create('news', $title, $t->user->id), [...$t->data, 'title' => $title, 'status' => 'published'], $t->user->id);
}

test('publishing, editing, unpublishing and trashing a post queue its address, each after the save', function () {
    Bus::fake([SubmitIndexNow::class, GenerateMediaVariants::class, PublishScheduledPost::class]);
    $saver = app(PostSaver::class);
    $post = $saver->create('news', 'خبر الطاقة', $this->user->id);

    $saver->save($post, [...$this->data, 'status' => 'draft'], $this->user->id);
    Bus::assertNotDispatched(SubmitIndexNow::class);            // a draft was never on the site

    $saver->save($post, [...$this->data, 'status' => 'published'], $this->user->id);
    $path = "/news/{$post->id}/".$post->fresh()->slug;
    $saver->save($post->fresh(), [...$this->data, 'status' => 'published', 'excerpt' => 'ملخص'], $this->user->id);
    $saver->save($post->fresh(), [...$this->data, 'status' => 'draft'], $this->user->id);
    $saver->trash($post->fresh());

    expect(indexNowQueued())->toBe(array_fill(0, 4, [$path]));
    Bus::assertDispatched(SubmitIndexNow::class, fn (SubmitIndexNow $job) => $job->afterCommit === true);
});

test('a slug change queues the new address and the old one, which now answers 301', function () {
    $post = publishedNews();
    $old = "/news/{$post->id}/".$post->slug;
    Bus::fake([SubmitIndexNow::class, GenerateMediaVariants::class, PublishScheduledPost::class]);

    app(PostSaver::class)->save($post->fresh(), [...$this->data, 'status' => 'published', 'slug' => 'عنوان-جديد'], $this->user->id);

    expect(indexNowQueued())->toBe([["/news/{$post->id}/عنوان-جديد", $old]]);
});

test('without a key, without the site address, or for a noindex post, nothing is queued', function () {
    Bus::fake([SubmitIndexNow::class, GenerateMediaVariants::class, PublishScheduledPost::class]);

    config(['raqam.indexnow.key' => '']);
    publishedNews('خبر 1');
    config(['raqam.indexnow.key' => 'short']);
    publishedNews('خبر 2');
    config(['raqam.indexnow.key' => INDEXNOW_TEST_KEY, 'raqam.site_url' => '']);
    publishedNews('خبر 3');
    config(['raqam.site_url' => 'http://raqam.example']);
    app()->detectEnvironment(fn () => 'production');
    publishedNews('خبر 4');
    app()->detectEnvironment(fn () => 'testing');

    config(['raqam.site_url' => 'https://raqam.example']);
    $saver = app(PostSaver::class);
    $post = $saver->save($saver->create('news', 'خبر 5', $this->user->id), [...$this->data, 'status' => 'draft'], $this->user->id);
    DB::table('posts')->where('id', $post->id)->update(['seo_meta_id' => DB::table('seo_meta')->insertGetId(['robots_noindex' => true])]);
    $saver->save($post->fresh(), [...$this->data, 'status' => 'published'], $this->user->id);

    Bus::assertNotDispatched(SubmitIndexNow::class);
});

test('a static page is queued when published, edited, re-addressed and taken off; a draft page is not', function () {
    Bus::fake([SubmitIndexNow::class]);

    $page = Page::query()->create(['title' => 'مسودة', 'slug' => 'draft-page', 'is_published' => false]);
    $page->update(['body' => '<p>نص</p>']);
    Bus::assertNotDispatched(SubmitIndexNow::class);

    $page->update(['is_published' => true]);
    $page->update(['slug' => 'about-us']);
    $page->update(['is_published' => false]);
    $page->update(['body' => '<p>نص آخر</p>']);                // off the site: an edit is nobody's business
    $page->update(['is_published' => true]);
    $page->delete();

    expect(indexNowQueued())->toBe([['/draft-page'], ['/about-us', '/draft-page'], ['/about-us'], ['/about-us'], ['/about-us']]);
});

test('the submission names the host, the key and its file, and every address in full, encoded', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);

    (new SubmitIndexNow(['/news/7/خبر-الطاقة', '/about-us']))->handle();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.indexnow.org/indexnow' && $r->method() === 'POST' && $r->data() === [
        'host' => 'raqam.example', 'key' => INDEXNOW_TEST_KEY, 'keyLocation' => 'https://raqam.example/'.INDEXNOW_TEST_KEY.'.txt',
        'urlList' => ['https://raqam.example/news/7/'.rawurlencode('خبر-الطاقة'), 'https://raqam.example/about-us'],
    ]);
});

test('the same address is not sent again within the gap; another one is', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

    (new SubmitIndexNow(['/news/7/x']))->handle();
    (new SubmitIndexNow(['/news/7/x']))->handle();
    (new SubmitIndexNow(['/news/7/x', '/news/8/y']))->handle();

    expect(Http::recorded()->map(fn ($pair) => $pair[0]->data()['urlList'])->all())
        ->toBe([['https://raqam.example/news/7/x'], ['https://raqam.example/news/8/y']]);

    config(['raqam.indexnow.key' => '']);
    (new SubmitIndexNow(['/news/9/z']))->handle();            // switched off since it was queued: nothing goes
    expect(Http::recorded())->toHaveCount(2);
});

test('a refusal that trying again cannot fix fails the job at once and the admins hear of it; a busy engine is tried again', function () {
    staffAdmin();
    Http::fake(['api.indexnow.org/*' => Http::sequence()->push('', 403)->push('', 429)->push('', 500)]);

    $job = (new SubmitIndexNow(['/news/1/x']))->withFakeQueueInteractions();
    $job->handle();
    $job->assertFailed();

    foreach (['/news/2/x', '/news/3/x'] as $path) {
        $job = (new SubmitIndexNow([$path]))->withFakeQueueInteractions();
        expect(fn () => $job->handle())->toThrow(RuntimeException::class);
        $job->assertNotFailed();
        expect(Cache::has('indexnow:'.hash('sha256', $path)))->toBeFalse();   // free to be sent on the next try
    }
    expect((new SubmitIndexNow([]))->tries)->toBe(3);
});

test('a failed submission raises the bell like any failed job, without the key', function () {
    staffAdmin();
    Http::fake(['api.indexnow.org/*' => Http::response('{"message":"key not valid"}', 403)]);

    dispatch_sync(new SubmitIndexNow(['/news/9/x']));

    $alert = DB::table('admin_notifications')->latest('id')->first();
    expect($alert)->not->toBeNull()->and(json_encode($alert, JSON_UNESCAPED_UNICODE))->toContain('SubmitIndexNow')->not->toContain(INDEXNOW_TEST_KEY);
});

test('production refuses a malformed key or a test endpoint', function () {
    productionConfig(['raqam.indexnow.key' => 'bad key!']);
    expect(ProductionChecks::failures())->toContain('INDEXNOW_KEY must be 8 to 128 letters, digits or dashes.');

    productionConfig(['raqam.indexnow.key' => INDEXNOW_TEST_KEY, 'raqam.indexnow.endpoint_override' => 'http://127.0.0.1:9/']);
    expect(ProductionChecks::failures())->toContain('INDEXNOW_ENDPOINT is for browser tests only and must not be set.');

    productionConfig(['raqam.indexnow.key' => '', 'raqam.indexnow.endpoint_override' => null]);
    expect(implode("\n", ProductionChecks::failures()))->not->toContain('INDEXNOW');
    expect(IndexNow::configured())->toBeFalse();
});
