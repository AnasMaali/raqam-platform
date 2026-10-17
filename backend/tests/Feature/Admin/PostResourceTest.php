<?php

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Post;
use App\Models\User;
use App\Services\PostRuleViolation;
use App\Services\PostSaver;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function staff(array $extra = []): User
{
    return User::find(insertUser('staff', 'staff'.uniqid().'@example.com', $extra));
}

function season(): int
{
    static $number = 100;

    return DB::table('podcast_seasons')->insertGetId(['season_number' => ++$number]);
}

function company(): int
{
    return DB::table('organizations')->insertGetId(['name' => 'شركة نابلس', 'slug' => 'company-'.uniqid()]);
}

function episodeForm(array $overrides = []): array
{
    return [
        'kind_code' => 'episode', 'title' => 'اقتصاد نابلس: الصابون، والحلقة الأولى', 'status' => 'published',
        'published_at' => now()->subHour()->toDateTimeString(), 'body' => '<p>نص الحلقة</p>',
        'cover_media_id' => insertMedia(), 'episode' => ['season_id' => season(), 'episode_number' => 1],
        ...$overrides,
    ];
}

// ---- who may enter ---------------------------------------------------------------------------

it('keeps guests, customers and inactive staff out of the panel', function () {
    $url = '/'.config('raqam.admin_path').'/posts';

    $this->get($url)->assertRedirect();
    $this->actingAs(User::find(insertUser('customer', 'c@example.com')))->get($url)->assertForbidden();
    $this->actingAs(staff(['is_active' => false]))->get($url)->assertForbidden();
    $this->actingAs(staff())->get($url)->assertOk();
});

it('renders right-to-left in Arabic', function () {
    $this->get('/'.config('raqam.admin_path').'/login')
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('lang="ar"', false);
});

// ---- the two kinds ---------------------------------------------------------------------------

it('saves a podcast episode and its subtype row from one form', function () {
    $this->actingAs(staff());

    Livewire::test(CreatePost::class)
        ->fillForm(episodeForm())
        ->call('create')
        ->assertHasNoFormErrors();

    $post = Post::firstOrFail();
    expect($post->kind_code)->toBe('episode')
        ->and($post->slug)->toBe('اقتصاد-نابلس-الصابون-والحلقة-الأولى')   // passed through slug_text, not Str::slug
        ->and($post->episode->episode_number)->toBe(1)
        ->and($post->created_by)->not->toBeNull();
});

it('saves a success story linked to an organization', function () {
    $this->actingAs(staff());
    $organization = company();

    Livewire::test(CreatePost::class)
        ->fillForm(episodeForm([
            'kind_code' => 'story', 'episode' => null,
            'story' => ['organization_id' => $organization, 'person_name' => 'أبو أحمد'],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::firstOrFail()->story->organization_id)->toBe($organization);
});

it('hides the section of the other kind', function () {
    $this->actingAs(staff());

    Livewire::test(CreatePost::class)
        ->fillForm(['kind_code' => 'episode'])
        ->assertFormFieldIsVisible('episode.season_id')
        ->assertFormFieldIsHidden('story.organization_id')
        ->fillForm(['kind_code' => 'story'])
        ->assertFormFieldIsHidden('episode.season_id')
        ->assertFormFieldIsVisible('story.organization_id');
});

it('lets an episode stay a draft without section data', function () {
    $this->actingAs(staff());

    Livewire::test(CreatePost::class)
        ->fillForm(['kind_code' => 'episode', 'title' => 'مسودة', 'status' => 'draft', 'slug' => 'مسودة'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::firstOrFail()->episode)->toBeNull();
});

it('asks for the section data before an episode can be published', function () {
    $this->actingAs(staff());

    Livewire::test(CreatePost::class)
        ->fillForm(episodeForm(['episode' => ['season_id' => null, 'episode_number' => null]]))
        ->call('create')
        ->assertHasFormErrors(['episode.season_id' => 'required', 'episode.episode_number' => 'required']);
});

it('rejects an episode number already used in the season', function () {
    $this->actingAs(staff());
    $season = season();

    Livewire::test(CreatePost::class)
        ->fillForm(episodeForm(['episode' => ['season_id' => $season, 'episode_number' => 4]]))
        ->call('create')->assertHasNoFormErrors();

    Livewire::test(CreatePost::class)
        ->fillForm(episodeForm(['title' => 'حلقة أخرى', 'episode' => ['season_id' => $season, 'episode_number' => 4]]))
        ->call('create')
        ->assertHasFormErrors(['episode.episode_number']);

    expect(Post::count())->toBe(1);
});

// ---- the trigger, reached through the real database ------------------------------------------

it('turns the deferred subtype trigger into an Arabic message inside the save', function () {
    $user = staff();
    $saver = app(PostSaver::class);

    $publish = fn () => $saver->create([
        'kind_code' => 'episode', 'title' => 'حلقة', 'slug' => 'حلقة', 'status' => 'published',
        'published_at' => now()->subMinute(), 'body' => '<p>x</p>', 'cover_media_id' => insertMedia(),
    ], $user->id);

    expect($publish)->toThrow(PostRuleViolation::class, 'قبل تعبئة بيانات قسمه');
    expect(Post::count())->toBe(0);   // the failed save left nothing behind
});

it('maps the duplicate episode number the form could not see (a race)', function () {
    $user = staff();
    $saver = app(PostSaver::class);
    $season = season();
    $data = fn ($slug) => [
        'kind_code' => 'episode', 'title' => $slug, 'slug' => $slug, 'status' => 'published',
        'published_at' => now()->subMinute(), 'body' => '<p>x</p>', 'cover_media_id' => insertMedia(),
        'episode' => ['season_id' => $season, 'episode_number' => 2],
    ];

    $saver->create($data('أولى'), $user->id);

    expect(fn () => $saver->create($data('ثانية'), $user->id))
        ->toThrow(PostRuleViolation::class, 'رقم الحلقة مستخدم');
});

// ---- the kind is locked ---------------------------------------------------------------------

it('locks the kind after creation, in the form and in the database', function () {
    $user = staff();
    $this->actingAs($user);
    $post = app(PostSaver::class)->create(episodeForm(['slug' => 'حلقة']), $user->id);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->assertFormFieldIsDisabled('kind_code');

    app(PostSaver::class)->update($post, ['kind_code' => 'story', 'title' => 'عنوان جديد'], $user->id);
    expect($post->fresh()->kind_code)->toBe('episode')->and($post->fresh()->title)->toBe('عنوان جديد');

    expect(fn () => DB::transaction(fn () => DB::table('posts')->where('id', $post->id)->update(['kind_code' => 'story'])))
        ->toThrow(QueryException::class, 'cannot change');
});

it('loads the subtype values when editing', function () {
    $user = staff();
    $this->actingAs($user);
    $post = app(PostSaver::class)->create(episodeForm(['slug' => 'حلقة', 'episode' => ['season_id' => season(), 'episode_number' => 9]]), $user->id);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->assertFormSet(['episode.episode_number' => 9, 'kind_code' => 'episode']);
});

// ---- the cover ------------------------------------------------------------------------------

it('only accepts an existing public image as the cover', function () {
    $this->actingAs(staff());
    $pdf = insertMedia(['mime_type' => 'application/pdf', 'width' => null, 'height' => null, 'alt_text' => null]);

    Livewire::test(CreatePost::class)
        ->fillForm(episodeForm(['cover_media_id' => $pdf]))
        ->call('create')
        ->assertHasFormErrors(['cover_media_id']);
});
