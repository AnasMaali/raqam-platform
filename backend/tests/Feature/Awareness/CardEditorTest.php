<?php

use App\Awareness\CharacterLibrary;
use App\Content\PostSaver;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Jobs\GenerateMediaVariants;
use App\Media\MediaUploader;
use App\Models\Character;
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
    Bus::fake([GenerateMediaVariants::class]);
    ensurePostKinds();
    $this->actingAs(User::factory()->staff()->create());
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->a = Character::create(['name_ar' => 'المستشار', 'slug' => 'advisor', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']);
    $this->b = Character::create(['name_ar' => 'انتبه!', 'slug' => 'watch-out', 'role_ar' => 'ينبّه', 'accent_color' => '#B3261E']);
    foreach ([$this->a, $this->b] as $c) {
        app(CharacterLibrary::class)->savePoses($c, ['explaining' => ['static_media_id' => app(MediaUploader::class)->store(uploadFile(imageBytes(200 + $c->id, 100)), 'p.jpg', ['alt_text' => 'و'], null)->asset->id]]);
    }
});

test('the card editor picks the character, then one of its poses, and publishes (7.15)', function () {
    $card = app(PostSaver::class)->create('awareness', 'نصيحة اليوم', auth()->id());
    $poseOfA = DB::table('character_poses')->where('character_id', $this->a->id)->value('id');

    $page = Livewire::test(EditPost::class, ['record' => $card->id])
        ->fillForm(['awareness.character_id' => $this->a->id, 'awareness.character_pose_id' => $poseOfA, 'awareness.awareness_type_code' => 'advice',
            'body' => '<p>ادّخر قبل أن تنفق</p>', 'cover_media_id' => $this->cover->id, 'status' => 'published'])
        ->call('save')->assertHasNoFormErrors();

    expect(DB::table('awareness_cards')->where('post_id', $card->id)->value('character_pose_id'))->toBe($poseOfA)->and($card->refresh()->status)->toBe('published');

    $reopened = Livewire::test(EditPost::class, ['record' => $card->id]);
    expect((int) $reopened->get('data.awareness.character_id'))->toBe($this->a->id)->and((int) $reopened->get('data.awareness.character_pose_id'))->toBe($poseOfA);
});

test('a card cannot be published with the character chosen but no pose', function () {
    $card = app(PostSaver::class)->create('awareness', 'نصيحة اليوم', auth()->id());

    Livewire::test(EditPost::class, ['record' => $card->id])
        ->fillForm(['awareness.character_id' => $this->a->id, 'awareness.awareness_type_code' => 'advice', 'body' => '<p>x</p>', 'cover_media_id' => $this->cover->id, 'status' => 'published'])
        ->call('save');

    expect($card->refresh()->status)->toBe('draft')->and(DB::table('awareness_cards')->count())->toBe(0);
});

test('a news item saves the cards picked for it from the editor (7.12)', function () {
    $card = app(PostSaver::class)->create('awareness', 'بطاقة', auth()->id());
    $news = app(PostSaver::class)->create('news', 'خبر', auth()->id());

    Livewire::test(EditPost::class, ['record' => $news->id])->fillForm(['awareness_cards' => [(string) $card->id]])->call('save')->assertHasNoFormErrors();
    expect(DB::table('post_related')->where('post_id', $news->id)->pluck('related_post_id')->map(fn ($i) => (int) $i)->all())->toBe([$card->id]);

    $reopened = Livewire::test(EditPost::class, ['record' => $news->id]);
    expect(array_map('intval', $reopened->get('data.awareness_cards')))->toBe([$card->id]);

    // The card's own editor has no such section.
    Livewire::test(EditPost::class, ['record' => $card->id])->assertDontSee('بطاقات توعية مرتبطة');
    $reopened->assertSee('بطاقات توعية مرتبطة');
});
