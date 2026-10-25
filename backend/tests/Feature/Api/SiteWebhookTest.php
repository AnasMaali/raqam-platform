<?php

use App\Content\PostSaver;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\PublishScheduledPost;
use App\Jobs\SendSiteWebhook;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Character;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class, PublishScheduledPost::class]);
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'x'], null)->asset;
    $this->data = ['title' => 'خبر', 'body' => '<p>نص</p>', 'category_id' => Category::value('id'), 'cover_media_id' => $this->cover->id];
});

function configureWebhook(string $url = 'https://site.example/hooks/raqam'): void
{
    config(['raqam.site_webhook.url' => $url, 'raqam.site_webhook.secret' => 'test-secret-0123456789']);
}

test('without a configured address nothing is queued and nothing is sent', function () {
    Bus::fake([SendSiteWebhook::class]);
    Http::fake();

    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);
    app(PostSaver::class)->save($post, [...$this->data, 'status' => 'published'], $this->user->id);

    Bus::assertNotDispatched(SendSiteWebhook::class);
    Http::assertNothingSent();
});

test('an address without a secret, or a plain http address in production, is not used', function () {
    config(['raqam.site_webhook.url' => 'https://site.example/h', 'raqam.site_webhook.secret' => '']);
    expect(SendSiteWebhook::configured())->toBeFalse();

    configureWebhook('http://site.example/h');
    expect(SendSiteWebhook::configured())->toBeTrue();   // fine while developing
    app()->detectEnvironment(fn () => 'production');
    expect(SendSiteWebhook::configured())->toBeFalse();
});

test('publishing, editing and trashing tell the site which address changed', function () {
    configureWebhook();
    Bus::fake([SendSiteWebhook::class]);
    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);
    app(PostSaver::class)->save($post, ['title' => 'خبر معدّل'], $this->user->id);
    Bus::assertNotDispatched(SendSiteWebhook::class);   // a draft is not on the site, so editing one refreshes nothing

    app(PostSaver::class)->save($post, [...$this->data, 'status' => 'published'], $this->user->id);
    Bus::assertDispatched(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'post' && $job->payload['id'] === $post->id && $job->payload['paths'] === ["/news/{$post->id}/".$post->fresh()->slug]);

    app(PostSaver::class)->trash($post->fresh());
    Bus::assertDispatched(SendSiteWebhook::class, fn ($job) => $job->payload['change'] === 'trashed');
});

test('a changed page tells the site too', function () {
    configureWebhook();
    Bus::fake([SendSiteWebhook::class]);

    Page::create(['title' => 'صفحة', 'slug' => 'about-us']);

    Bus::assertDispatched(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'page' && $job->payload['paths'] === ['/about-us']);
});

test('hiding or showing a character asks the site to refresh everything', function () {
    Filament\Facades\Filament::setCurrentPanel('admin');
    $character = Character::create(['name_ar' => 'المستشار', 'slug' => 'advisor', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']);
    configureWebhook();
    Bus::fake([SendSiteWebhook::class]);
    $everything = fn (string $change) => fn ($job) => $job->payload['type'] === 'all' && $job->payload['change'] === $change && $job->payload['id'] === $character->id && $job->payload['paths'] === [];

    Livewire\Livewire::test(EditCharacter::class, ['record' => $character->id])->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);
    Bus::assertDispatched(SendSiteWebhook::class, $everything('character_hidden'));

    $character->refresh()->update(['is_active' => true]);
    Bus::assertDispatched(SendSiteWebhook::class, $everything('character_shown'));

    $character->update(['is_active' => true]);              // already shown: nothing changed, nothing sent
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 2);
});

test('the request is signed over its time and body, so the site can check it and refuse old ones', function () {
    configureWebhook();
    Http::fake(['site.example/*' => Http::response('', 200)]);

    (new SendSiteWebhook(['type' => 'post', 'id' => 7, 'change' => 'content', 'paths' => ['/news/7/x'], 'at' => '2026-10-18T10:00:00Z']))->handle();

    Http::assertSent(function (Request $request) {
        $time = $request->header('X-Raqam-Timestamp')[0];
        $expected = 'sha256='.hash_hmac('sha256', $time.'.'.$request->body(), 'test-secret-0123456789');

        return $request->url() === 'https://site.example/hooks/raqam' && $request->header('X-Raqam-Signature')[0] === $expected
            && abs(time() - (int) $time) < 5 && json_decode($request->body(), true)['paths'] === ['/news/7/x'] && $request->hasHeader('Content-Type', 'application/json');
    });
});

test('the signature changes with the secret and with the body', function () {
    $sign = fn (string $secret, string $body) => hash_hmac('sha256', '100.'.$body, $secret);

    expect($sign('a', '{}'))->not->toBe($sign('b', '{}'))->and($sign('a', '{}'))->not->toBe($sign('a', '{"x":1}'));
});

test('a site that answers with an error makes the job fail so it is tried again', function () {
    configureWebhook();
    Http::fake(['site.example/*' => Http::response('', 500)]);

    expect(fn () => (new SendSiteWebhook(['type' => 'all']))->handle())->toThrow(RequestException::class);
    expect((new SendSiteWebhook([]))->tries)->toBe(4)->and((new SendSiteWebhook([]))->backoff)->toBe([5, 30, 120]);
});

test('the refresh button on the dashboard asks the site to refresh everything, or says it is not set up', function () {
    Filament\Facades\Filament::setCurrentPanel('admin');
    Bus::fake([SendSiteWebhook::class]);

    Livewire\Livewire::test(Dashboard::class)->callAction('refreshSite')->assertNotified();
    Bus::assertNotDispatched(SendSiteWebhook::class);

    configureWebhook();
    Livewire\Livewire::test(Dashboard::class)->callAction('refreshSite')->assertNotified();
    Bus::assertDispatched(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'all');
});

test('a post change sends one webhook, not one per way its listener was found', function () {
    configureWebhook();
    Bus::fake([SendSiteWebhook::class]);
    $post = app(PostSaver::class)->create('news', 'خبر', $this->user->id);

    app(PostSaver::class)->save($post, [...$this->data, 'status' => 'published'], $this->user->id);

    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);
});

test('no listener is registered twice for the same event', function () {
    foreach (app('events')->getRawListeners() as $event => $listeners) {
        $names = array_filter(array_map(fn ($l) => is_string($l) ? preg_replace('/@handle$/', '', $l) : null, $listeners));
        expect(array_unique($names))->toHaveCount(count($names), $event);
    }
});
