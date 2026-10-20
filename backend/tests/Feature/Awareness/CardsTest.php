<?php

use App\Awareness\CharacterLibrary;
use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Content\Subtypes\SubtypeRegistry;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\PublishScheduledPost;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Character;
use App\Models\Guest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class, PublishScheduledPost::class]);
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->saver = app(PostSaver::class);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->energy = Category::where('slug', 'طاقة')->first();
    $this->character = Character::create(['name_ar' => 'المستشار', 'slug' => 'advisor', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']);
    $pic = app(MediaUploader::class)->store(uploadFile(imageBytes(220, 200)), 'p.jpg', ['alt_text' => 'وضعية'], null)->asset->id;
    $pic2 = app(MediaUploader::class)->store(uploadFile(imageBytes(230, 200)), 'q.jpg', ['alt_text' => 'وضعية'], null)->asset->id;
    app(CharacterLibrary::class)->savePoses($this->character, ['explaining' => ['static_media_id' => $pic], 'warning' => ['static_media_id' => $pic2]]);
    $this->pose = DB::table('character_poses')->where('character_id', $this->character->id)->where('pose_type_code', 'explaining')->value('id');
});

function newCard(array $over = [], bool $publish = true): int
{
    $t = test();
    $post = app(PostSaver::class)->create('awareness', 'بطاقة '.random_int(1, 999999), $t->user->id);
    $data = ['title' => $post->title, 'body' => '<p>معلومة</p>', 'cover_media_id' => $t->cover->id, 'awareness' => ['character_pose_id' => $t->pose, 'awareness_type_code' => 'advice']];
    $publish && $data['status'] = 'published';

    return app(PostSaver::class)->save($post, array_replace_recursive($data, $over), $t->user->id)->id;
}

function newNews(array $extra = []): int
{
    $t = test();
    $post = app(PostSaver::class)->create('news', 'خبر '.random_int(1, 999999), $t->user->id);

    return app(PostSaver::class)->save($post, array_merge(['title' => $post->title, 'body' => '<p>نص</p>', 'category_id' => $t->energy->id, 'cover_media_id' => $t->cover->id, 'status' => 'published'], $extra), $t->user->id)->id;
}

test('a card is saved with a character\'s pose and a kind of information (7.11)', function () {
    $id = newCard();

    expect(DB::table('awareness_cards')->where('post_id', $id)->first())->character_pose_id->toBe($this->pose)->awareness_type_code->toBe('advice');
});

test('a card cannot be published without a pose, and half-filled facts are refused (7.15)', function () {
    $post = $this->saver->create('awareness', 'بطاقة', $this->user->id);

    expect(fn () => $this->saver->save($post, ['title' => 'بطاقة', 'body' => '<p>x</p>', 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->user->id))->toThrow(PostRuleViolation::class);
    expect(fn () => $this->saver->save($post->refresh(), ['title' => 'بطاقة', 'awareness' => ['awareness_type_code' => 'advice']], $this->user->id))->toThrow(PostRuleViolation::class, 'ناقصة');
    expect(DB::table('posts')->where('id', $post->id)->value('status'))->toBe('draft');
});

test('a pose that does not exist, or whose character is hidden, is refused', function () {
    $post = $this->saver->create('awareness', 'بطاقة', $this->user->id);

    expect(fn () => $this->saver->save($post, ['title' => 'بطاقة', 'awareness' => ['character_pose_id' => 987654, 'awareness_type_code' => 'advice']], $this->user->id))->toThrow(PostRuleViolation::class);

    $this->character->update(['is_active' => false]);
    expect(fn () => $this->saver->save($post->refresh(), ['title' => 'بطاقة', 'awareness' => ['character_pose_id' => $this->pose, 'awareness_type_code' => 'advice']], $this->user->id))->toThrow(PostRuleViolation::class, 'مخفية');
});

test('the form loads the character of the saved pose', function () {
    $id = newCard();

    expect(app(SubtypeRegistry::class)->for('awareness')->load($id))->toMatchArray(['character_pose_id' => $this->pose, 'character_id' => $this->character->id]);
});

// ---- cards linked to news (7.12) ------------------------------------------------------------------

test('cards picked for a news item are linked to it, together with the cards in its text', function () {
    $picked = newCard();
    $inText = newCard();
    $news = newNews(['body' => '<p>x</p><div data-type="customBlock" data-id="awareness" data-config=\'{"post_id":'.$inText.'}\'></div>', 'awareness_cards' => [$picked]]);

    expect(DB::table('post_related')->where('post_id', $news)->orderBy('sort_order')->pluck('related_post_id')->map(fn ($i) => (int) $i)->all())->toBe([$inText, $picked]);
});

test('saving without mentioning the cards keeps the ones linked, and an empty list removes the picked ones', function () {
    $picked = newCard();
    $news = newNews(['awareness_cards' => [$picked]]);
    $post = Post::find($news);

    $this->saver->save($post, ['title' => 'عنوان آخر', 'status' => 'published', 'category_id' => $this->energy->id, 'cover_media_id' => $this->cover->id, 'body' => '<p>x</p>'], $this->user->id);
    expect(DB::table('post_related')->where('post_id', $news)->count())->toBe(1);

    $this->saver->save($post->refresh(), ['title' => 'عنوان آخر', 'status' => 'published', 'category_id' => $this->energy->id, 'cover_media_id' => $this->cover->id, 'body' => '<p>x</p>', 'awareness_cards' => []], $this->user->id);
    expect(DB::table('post_related')->where('post_id', $news)->count())->toBe(0);
});

test('only awareness cards can be linked, never a news item, a missing id or garbage', function (mixed $bad) {
    $news = Post::find(newNews());
    $other = newNews();

    expect(fn () => $this->saver->save($news, ['title' => 'x', 'awareness_cards' => [$bad === 'OTHER' ? $other : $bad]], $this->user->id))->toThrow(PostRuleViolation::class);
})->with(['OTHER', 987654, 'abc', '1; drop table posts', -3]);

test('a card links to no cards', function () {
    $a = newCard();
    $card = Post::find(newCard());

    $this->saver->save($card, ['title' => 'x', 'body' => '<p>x</p>', 'cover_media_id' => $this->cover->id, 'status' => 'published', 'awareness' => ['character_pose_id' => $this->pose, 'awareness_type_code' => 'advice'], 'awareness_cards' => [$a]], $this->user->id);

    expect(DB::table('post_related')->where('post_id', $card->id)->count())->toBe(0);
});

test('no more than twenty cards are linked', function () {
    $news = Post::find(newNews());
    $ids = collect(range(1, 21))->map(fn () => newCard(publish: false))->all();

    expect(fn () => $this->saver->save($news, ['title' => 'x', 'body' => '<p>x</p>', 'category_id' => $this->energy->id, 'cover_media_id' => $this->cover->id, 'status' => 'published', 'awareness_cards' => $ids], $this->user->id))->toThrow(PostRuleViolation::class, '20');
});

// ---- the API (7.12, 7.13, 5.10, 6.11) ---------------------------------------------------------------

test('a news item lists its published linked cards with their character and pose, and hides drafts', function () {
    $live = newCard();
    $draft = newCard(publish: false);
    $news = newNews(['awareness_cards' => [$live, $draft]]);

    $data = api("/posts/$news")->assertOk()->json('data');

    matchesContract($data, 'PostDetail');
    expect(collect($data['awareness_cards'])->pluck('id')->all())->toBe([$live])
        ->and($data['awareness_cards'][0]['awareness']['character']['slug'])->toBe('advisor')
        ->and($data['awareness_cards'][0]['awareness']['pose']['code'])->toBe('explaining')
        ->and($data['awareness_cards'][0]['awareness']['pose']['image']['url'])->not->toBeEmpty();
});

test('every character answers with its poses, colour and card count (7.13, 7.14)', function () {
    newCard();
    newCard(['awareness' => ['character_pose_id' => DB::table('character_poses')->where('pose_type_code', 'warning')->value('id'), 'awareness_type_code' => 'warning']]);
    newCard(publish: false);

    $one = api('/characters/advisor')->assertOk()->json('data');
    matchesContract($one, 'Character');
    expect($one['name'])->toBe('المستشار')->and($one['accent_color'])->toBe('#1F6F5B')->and($one['cards_count'])->toBe(2)
        ->and(collect($one['poses'])->pluck('code')->all())->toBe(['explaining', 'warning'])->and($one['rive'])->toBeNull();

    $all = api('/characters')->assertOk()->json('data');
    expect($all)->toHaveCount(1);
    matchesContract($all[0], 'Character');
});

test('a hidden character does not exist for visitors', function () {
    newCard();
    $this->character->update(['is_active' => false]);

    api('/characters/advisor')->assertNotFound();
    api('/characters/advisor/cards')->assertNotFound();
    expect(api('/characters')->json('data'))->toBe([])->and(api('/awareness')->json('data.latest'))->toBe([]);
});

test('the character page lists all of its published cards, newest first, a page at a time', function () {
    $ids = collect(range(1, 14))->map(fn ($i) => newCard(['published_at' => now()->subMinutes(30 - $i)]))->all();
    newCard(publish: false);

    $page1 = api('/characters/advisor/cards')->assertOk();

    expect($page1->json('meta.total'))->toBe(14)->and($page1->json('data'))->toHaveCount(12)->and($page1->json('data.0.id'))->toBe($ids[13]);
    matchesContract($page1->json('data.0'), 'AwarenessCard');
    expect(api('/characters/advisor/cards?page=2')->json('data'))->toHaveCount(2);
    api('/characters/advisor/cards?page=0')->assertStatus(422);
});

test('the awareness page gives the section, the characters and the newest cards', function () {
    newCard();

    $data = api('/awareness')->assertOk()->json('data');

    expect($data['section']['key'])->toBe('awareness')->and($data['characters'])->toHaveCount(1)->and($data['latest'])->toHaveCount(1);
    matchesContract($data['section'], 'Section');
    matchesContract($data['latest'][0], 'AwarenessCard');
});

test('the awareness page is not there while the section is hidden', function () {
    DB::table('sections')->where('key', 'awareness')->update(['is_visible' => false]);

    api('/awareness')->assertNotFound();
});

test('a guest page lists every published appearance with the part played (5.10, 6.11)', function () {
    $guest = Guest::create(['name' => 'ضيف الحلقة', 'slug' => 'guest-one']);
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $episode = $this->saver->create('episode', 'الحلقة الأولى', $this->user->id);
    $this->saver->save($episode, ['title' => 'الحلقة الأولى', 'body' => '<p>x</p>', 'cover_media_id' => $this->cover->id, 'status' => 'published',
        'episode' => ['season_id' => $season, 'episode_number' => 1], 'guests' => [['guest_id' => $guest->id, 'role_code' => 'guest']]], $this->user->id);
    $round = $this->saver->create('roundtable', 'جلسة', $this->user->id);
    $this->saver->save($round, ['title' => 'جلسة', 'body' => '<p>x</p>', 'cover_media_id' => $this->cover->id, 'status' => 'published',
        'roundtable' => ['held_on' => '2026-09-01'], 'guests' => [['guest_id' => $guest->id, 'role_code' => 'panelist']]], $this->user->id);
    $draft = $this->saver->create('episode', 'مسودة', $this->user->id);
    $this->saver->save($draft, ['title' => 'مسودة', 'guests' => [['guest_id' => $guest->id, 'role_code' => 'host']]], $this->user->id);

    $data = api('/guests/guest-one')->assertOk()->json('data');

    matchesContract($data, 'GuestPage');
    expect(collect($data['appearances'])->map(fn ($a) => [$a['post']['title'], $a['role']['code']])->sort()->values()->all())
        ->toBe([['الحلقة الأولى', 'guest'], ['جلسة', 'panelist']]);
    matchesContract(api('/guests')->json('data.0'), 'Guest');
});

test('a card inside a news text shows the character, its pose and colour, and escapes everything', function () {
    $card = newCard(['title' => 'نصيحة <script>x</script>']);
    $news = newNews(['body' => '<p>x</p><div data-type="customBlock" data-id="awareness" data-config=\'{"post_id":'.$card.'}\'></div>']);

    $html = api("/posts/$news")->json('data.body_html');

    expect($html)->toContain('class="rq-awareness"')->toContain('--rq-accent:#1F6F5B')->toContain('data-character="advisor"')
        ->toContain('<span>المستشار</span>')->toContain('<picture>')->not->toContain('<script>x');
});
