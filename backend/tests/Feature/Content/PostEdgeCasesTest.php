<?php

use App\Content\BodySanitizer;
use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    Bus::fake();
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->saver = app(PostSaver::class);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->data = ['title' => 'خبر', 'body' => '<p>نص</p>', 'category_id' => Category::value('id'), 'cover_media_id' => $this->cover->id];
});

function violationField(Closure $do): ?string
{
    try {
        $do();
    } catch (PostRuleViolation $e) {
        return $e->field;
    }

    return 'none';
}

test('a block can hold at most what its kind allows, even when every id is real', function () {
    $media = collect(range(1, 26))->map(fn () => insertMedia())->all();
    $posts = collect(range(1, 7))->map(fn () => insertPost())->all();
    $blocks = app(BodySanitizer::class);
    $make = fn (string $id, array $config) => '<div data-type="customBlock" data-id="'.$id.'" data-config=\''.json_encode($config).'\'></div>';

    expect($blocks->clean($make('gallery', ['media_ids' => array_slice($media, 0, 24)])))->not->toBe('')
        ->and($blocks->clean($make('gallery', ['media_ids' => $media])))->toBe('')
        ->and($blocks->clean($make('read-also', ['post_ids' => array_slice($posts, 0, 6)])))->not->toBe('')
        ->and($blocks->clean($make('read-also', ['post_ids' => $posts])))->toBe('');
});

test('the gallery of an item has a limit even when every picture is real', function () {
    $media = collect(range(1, 41))->map(fn () => insertMedia())->all();
    $post = $this->saver->create('news', 'خبر', $this->user->id);

    expect(violationField(fn () => $this->saver->save($post, [...$this->data, 'gallery' => $media], $this->user->id)))->toBe('gallery');
    expect(violationField(fn () => $this->saver->save($post, [...$this->data, 'gallery' => array_slice($media, 0, 40)], $this->user->id)))->toBe('none');
});

test('a breaking end time in the past is refused even if it is after the publishing time', function () {
    $post = $this->saver->create('news', 'خبر', $this->user->id);
    $this->saver->save($post, [...$this->data, 'status' => 'published'], $this->user->id);
    $this->travel(3)->hours();

    expect(violationField(fn () => $this->saver->save($post->fresh(), [...$this->data, 'status' => 'published', 'breaking_until' => now()->subHour()], $this->user->id)))->toBe('breaking_until');
});

test('the same breaking time that has now passed does not block an unrelated edit', function () {
    $post = $this->saver->create('news', 'خبر', $this->user->id);
    $this->saver->save($post, [...$this->data, 'status' => 'published', 'breaking_until' => now()->addHour()], $this->user->id);
    $until = $post->fresh()->breaking_until;
    $this->travel(2)->hours();

    $this->saver->save($post->fresh(), [...$this->data, 'title' => 'عنوان معدّل', 'status' => 'published', 'breaking_until' => $until], $this->user->id);

    expect($post->fresh()->title)->toBe('عنوان معدّل');
});

test('saving works even when the outer transaction left the deferred rule switched to immediate', function () {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $post = $this->saver->create('episode', 'حلقة', $this->user->id);

    DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');   // what a previous save in the same transaction used to leave behind

    $this->saver->save($post, [...$this->data, 'status' => 'published', 'episode' => ['season_id' => $season, 'episode_number' => 1]], $this->user->id);

    expect($post->fresh()->status)->toBe('published');
});

test('saving the same figures again writes nothing new to the audit trail', function () {
    $org = Organization::create(['name' => 'ش', 'slug' => 'sh']);
    $post = $this->saver->create('story', 'قصة', $this->user->id);
    $data = ['title' => 'قصة', 'story' => ['organization_id' => $org->id, 'figures' => [['value' => '5', 'label' => 'فروع']]]];

    $this->saver->save($post, $data, $this->user->id);
    $this->saver->save($post->fresh(), $data, $this->user->id);

    expect(DB::table('audit_log_changes')->where('field', 'story.figures')->count())->toBe(1);
});

test('two guests that are the same person are refused with a message about the guest', function () {
    $guest = Guest::create(['name' => 'ض', 'slug' => 'd']);
    $post = $this->saver->create('episode', 'حلقة', $this->user->id);

    try {
        $this->saver->save($post, [...$this->data, 'guests' => [['guest_id' => $guest->id, 'role_code' => 'host'], ['guest_id' => $guest->id, 'role_code' => 'guest']]], $this->user->id);
        test()->fail('Accepted.');
    } catch (PostRuleViolation $e) {
        expect($e->field)->toBe('guests')->and($e->getMessage())->toContain('مكرر');
    }
});

test('a published item does not even try to autosave, so no failure message is shown', function () {
    $post = $this->saver->create('news', 'خبر', $this->user->id);
    $this->saver->save($post, [...$this->data, 'status' => 'published'], $this->user->id);

    Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['body' => '<p>غيّرت</p>'])->call('autosave')
        ->assertNotDispatched('post-autosave-failed')->assertNotDispatched('post-autosaved');
});

test('only a draft can be autosaved, whatever the page does', function () {
    $post = $this->saver->create('news', 'خبر', $this->user->id);
    $this->saver->save($post, [...$this->data, 'status' => 'scheduled', 'published_at' => now()->addDay()], $this->user->id);

    expect(violationField(fn () => $this->saver->autosave($post->fresh(), ['body' => '<p>x</p>'], $this->user->id)))->not->toBe('none');
});

test('an item with the same title as another gets its own id in the address, never a clash', function () {
    $a = $this->saver->create('news', 'عنوان مكرر', $this->user->id);
    $b = $this->saver->create('news', 'عنوان مكرر', $this->user->id);

    expect($a->slug)->toBe($b->slug)->and($a->id)->not->toBe($b->id);   // the id in /news/{id}/{slug} is what tells them apart
});
