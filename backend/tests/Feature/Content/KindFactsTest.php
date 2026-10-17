<?php

use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Content\Subtypes\SubtypeRegistry;
use App\Media\MediaUploader;
use App\Models\Era;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake();
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->saver = app(PostSaver::class);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->org = Organization::create(['name' => 'شركة النور', 'slug' => 'noor']);
    $this->season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $this->era = Era::create(['name_ar' => 'العثماني', 'sort_order' => 1]);

    // A character with a pose, as the awareness phase will make them.
    $mediaId = $this->cover->id;
    $character = DB::table('characters')->insertGetId(['name_ar' => 'رَقَم', 'slug' => 'raqam', 'role_ar' => 'الراوي', 'accent_color' => '#C8962B']);
    $this->pose = DB::table('character_poses')->insertGetId(['character_id' => $character, 'pose_type_code' => 'explaining', 'static_media_id' => $mediaId]);
});

/** The facts of each kind that make a valid publishable item. */
function facts(string $kind): array
{
    $t = test();

    return match ($kind) {
        'episode' => ['season_id' => $t->season, 'episode_number' => 7],
        'story' => ['organization_id' => $t->org->id, 'person_name' => 'أبو خالد'],
        'product' => ['product_name' => 'صابون نابلسي', 'organization_id' => $t->org->id, 'contact_phone' => '+970599123456'],
        'history' => ['era_id' => $t->era->id, 'period_label' => 'القرن التاسع عشر', 'year_from' => 1850, 'year_to' => 1900],
        'initiative' => ['initiative_name' => 'مبادرة التمكين', 'organizer_organization_id' => $t->org->id, 'status' => 'ongoing'],
        'roundtable' => ['held_on' => '2026-09-01'],
        'awareness' => ['character_pose_id' => $t->pose, 'awareness_type_code' => 'did_you_know'],
        'infographic' => ['download_media_id' => $t->cover->id],
        default => [],
    };
}

function publishData(string $kind, array $extra = []): array
{
    return ['title' => 'عنوان '.$kind, 'body' => '<p>نص</p>', 'cover_media_id' => test()->cover->id, 'category_id' => DB::table('categories')->value('id'),
        'status' => 'published', $kind => facts($kind), ...$extra];
}

function expectField(Closure $do, string $field): void
{
    try {
        $do();
    } catch (PostRuleViolation $e) {
        expect($e->field)->toBe($field);

        return;
    }
    test()->fail("Nothing was refused for {$field}.");
}

dataset('kinds', ['episode', 'story', 'product', 'history', 'initiative', 'roundtable', 'awareness', 'infographic']);

test('every kind with facts of its own has a subtype, and the three general kinds have none', function () {
    $registry = app(SubtypeRegistry::class);

    expect(array_keys($registry->all()))->toEqualCanonicalizing(['episode', 'story', 'product', 'history', 'initiative', 'roundtable', 'awareness', 'infographic']);
    foreach (['news', 'report', 'opinion'] as $kind) {
        expect($registry->for($kind))->toBeNull();
    }
});

test('each subtype names a real table and real columns', function (string $kind) {
    $subtype = app(SubtypeRegistry::class)->for($kind);

    foreach (array_keys($subtype->rules()) as $column) {
        expect(Schema::hasColumn($subtype->table(), $column))->toBeTrue("{$subtype->table()}.{$column}");
    }
    expect(array_diff($subtype->required(), array_keys($subtype->rules())))->toBe([]);
})->with('kinds');

test('every kind can be published with its facts, and they are stored', function (string $kind) {
    $post = $this->saver->create($kind, 'عنوان '.$kind, $this->user->id);

    $this->saver->save($post, publishData($kind), $this->user->id);

    $subtype = app(SubtypeRegistry::class)->for($kind);
    $row = DB::table($subtype->table())->where('post_id', $post->id)->first();
    expect($post->fresh()->status)->toBe('published')->and($row)->not->toBeNull();
    foreach (facts($kind) as $column => $value) {
        expect((string) $row->{$column})->toBe((string) $value);
    }
})->with('kinds');

test('every kind with facts cannot be published without them, and the item stays a draft', function (string $kind) {
    $post = $this->saver->create($kind, 'عنوان '.$kind, $this->user->id);

    expect(fn () => $this->saver->save($post, publishData($kind, [$kind => []]), $this->user->id))->toThrow(PostRuleViolation::class, 'قبل تعبئة بيانات نوعها');

    expect($post->fresh()->status)->toBe('draft')->and(DB::table('posts')->where('id', $post->id)->value('status'))->toBe('draft');
})->with('kinds');

test('a draft may leave its facts empty, and later fill them', function (string $kind) {
    $post = $this->saver->create($kind, 'عنوان '.$kind, $this->user->id);

    $this->saver->save($post, ['title' => 'عنوان', $kind => []], $this->user->id);
    expect(DB::table(app(SubtypeRegistry::class)->for($kind)->table())->where('post_id', $post->id)->exists())->toBeFalse();

    $this->saver->save($post->fresh(), publishData($kind, ['status' => 'draft']), $this->user->id);
    expect(DB::table(app(SubtypeRegistry::class)->for($kind)->table())->where('post_id', $post->id)->exists())->toBeTrue();
})->with('kinds');

test('facts that are half filled are refused with the missing field named', function (string $kind) {
    $post = $this->saver->create($kind, 'عنوان '.$kind, $this->user->id);
    $required = app(SubtypeRegistry::class)->for($kind)->required();
    $partial = array_slice(facts($kind), 0, 1);
    if (count($required) < 2) {
        // A kind with one required fact cannot be half filled; give it only optional facts instead.
        $partial = array_diff_key(facts($kind), array_flip($required));
        if ($partial === []) {
            expect(true)->toBeTrue();

            return;
        }
    }

    try {
        $this->saver->save($post, ['title' => 'x', $kind => $partial], $this->user->id);
        test()->fail('Half-filled facts were accepted.');
    } catch (PostRuleViolation $e) {
        expect($e->field)->toStartWith("{$kind}.");
    }
})->with('kinds');

test('a fact that is wrong is refused with its own field, per kind', function (string $kind, string $column, mixed $bad) {
    $post = $this->saver->create($kind, 'عنوان', $this->user->id);

    expectField(fn () => $this->saver->save($post, ['title' => 'x', $kind => array_merge(facts($kind), [$column => $bad])], $this->user->id), "{$kind}.{$column}");
})->with([
    'season that does not exist' => ['episode', 'season_id', 99999],
    'season as text' => ['episode', 'season_id', 'abc'],
    'episode number zero' => ['episode', 'episode_number', 0],
    'episode number huge' => ['episode', 'episode_number', 99999],
    'episode number as text' => ['episode', 'episode_number', 'x'],
    'company that does not exist' => ['story', 'organization_id', 99999],
    'person name too long' => ['story', 'person_name', str_repeat('ا', 121)],
    'product name missing' => ['product', 'product_name', ''],
    'product phone not international' => ['product', 'contact_phone', '0599123456'],
    'product url not a web address' => ['product', 'contact_url', 'javascript:alert(1)'],
    'era that does not exist' => ['history', 'era_id', 99999],
    'years reversed' => ['history', 'year_to', 1800],
    'period label too long' => ['history', 'period_label', str_repeat('ا', 81)],
    'initiative status unknown' => ['initiative', 'status', 'paused'],
    'initiative url not a web address' => ['initiative', 'url', 'ftp://x'],
    'organiser missing' => ['initiative', 'organizer_organization_id', 99999],
    'session date wrong' => ['roundtable', 'held_on', 'tomorrow'],
    'session date impossible' => ['roundtable', 'held_on', '2026-02-31'],
    'pose that does not exist' => ['awareness', 'character_pose_id', 99999],
    'awareness type unknown' => ['awareness', 'awareness_type_code', 'rumour'],
    'download file missing' => ['infographic', 'download_media_id', 99999],
]);

test('an infographic download must be a public picture or PDF, not a private file or a trashed one', function () {
    Storage::fake('media_local_private');
    $uploader = app(MediaUploader::class);
    $private = $uploader->store(uploadFile(imageBytes(310, 200)), 'p.jpg', ['visibility' => 'private'], null)->asset;
    $pdf = $uploader->store(uploadFile("%PDF-1.4\n%%EOF"), 'f.pdf', [], null)->asset;
    $post = $this->saver->create('infographic', 'رسم', $this->user->id);

    expectField(fn () => $this->saver->save($post, ['title' => 'x', 'infographic' => ['download_media_id' => $private->id]], $this->user->id), 'infographic.download_media_id');

    $this->saver->save($post->fresh(), ['title' => 'x', 'infographic' => ['download_media_id' => $pdf->id]], $this->user->id);
    expect(DB::table('infographics')->where('post_id', $post->id)->value('download_media_id'))->toBe($pdf->id);
});

test('the facts of an item can be changed, and a changed fact is in the audit trail', function () {
    $post = $this->saver->create('product', 'منتج', $this->user->id);
    $this->saver->save($post, ['title' => 'x', 'product' => facts('product')], $this->user->id);

    $this->saver->save($post->fresh(), ['title' => 'x', 'product' => array_merge(facts('product'), ['product_name' => 'زيت زيتون'])], $this->user->id);

    expect(DB::table('products')->where('post_id', $post->id)->value('product_name'))->toBe('زيت زيتون');
    $change = DB::table('audit_log_changes')->where('field', 'product.product_name')->orderByDesc('audit_id')->first();
    expect($change)->old_value->toBe('صابون نابلسي')->new_value->toBe('زيت زيتون');
});

test('facts cannot be emptied once they exist', function () {
    $post = $this->saver->create('episode', 'حلقة', $this->user->id);
    $this->saver->save($post, ['title' => 'x', 'episode' => facts('episode')], $this->user->id);

    expectField(fn () => $this->saver->save($post->fresh(), ['title' => 'x', 'episode' => []], $this->user->id), 'episode.season_id');
    expect(DB::table('podcast_episodes')->where('post_id', $post->id)->exists())->toBeTrue();
});

test('the facts of one item never land on another', function () {
    $a = $this->saver->create('episode', 'أ', $this->user->id);
    $b = $this->saver->create('episode', 'ب', $this->user->id);
    $this->saver->save($a, ['title' => 'أ', 'episode' => ['season_id' => $this->season, 'episode_number' => 1]], $this->user->id);

    $this->saver->save($b, ['title' => 'ب', 'episode' => ['season_id' => $this->season, 'episode_number' => 2]], $this->user->id);

    expect(DB::table('podcast_episodes')->orderBy('post_id')->pluck('episode_number', 'post_id')->map(fn ($n) => (int) $n)->all())->toBe([$a->id => 1, $b->id => 2]);
});

test('the same episode number twice in a season is refused, in another season it is fine', function () {
    $second = DB::table('podcast_seasons')->insertGetId(['season_number' => 2]);
    $a = $this->saver->create('episode', 'أ', $this->user->id);
    $b = $this->saver->create('episode', 'ب', $this->user->id);
    $this->saver->save($a, ['title' => 'أ', 'episode' => ['season_id' => $this->season, 'episode_number' => 5]], $this->user->id);

    expectField(fn () => $this->saver->save($b, ['title' => 'ب', 'episode' => ['season_id' => $this->season, 'episode_number' => 5]], $this->user->id), 'episode.episode_number');
    $this->saver->save($b->fresh(), ['title' => 'ب', 'episode' => ['season_id' => $second, 'episode_number' => 5]], $this->user->id);
    expect(DB::table('podcast_episodes')->count())->toBe(2);

    // The same item saved again with its own number is not a clash with itself.
    $this->saver->save($a->fresh(), ['title' => 'أ', 'episode' => ['season_id' => $this->season, 'episode_number' => 5]], $this->user->id);
});

test('the deferred rule is put back after every save, so an outer transaction is not left in a strict mode', function () {
    $post = $this->saver->create('episode', 'حلقة', $this->user->id);
    $this->saver->save($post, publishData('episode', ['status' => 'draft']), $this->user->id);

    // If the mode were left IMMEDIATE, inserting a published episode before its row would fail here.
    $id = DB::table('posts')->insertGetId(['kind_code' => 'episode', 'title' => 'ت', 'slug' => 'ت', 'status' => 'published', 'published_at' => now(), 'cover_media_id' => $this->cover->id, 'body' => 'x']);
    DB::table('podcast_episodes')->insert(['post_id' => $id, 'season_id' => $this->season, 'episode_number' => 99]);

    expect(DB::table('podcast_episodes')->where('post_id', $id)->exists())->toBeTrue();
});

test('story figures are saved in order, replaced as a set, and limited', function () {
    $post = $this->saver->create('story', 'قصة', $this->user->id);
    $figures = [['value' => '120', 'label' => 'موظفاً'], ['value' => '15', 'label' => 'سنة']];

    $this->saver->save($post, ['title' => 'x', 'story' => facts('story') + ['figures' => $figures]], $this->user->id);
    expect(DB::table('success_story_figures')->where('post_id', $post->id)->orderBy('sort_order')->get(['value', 'label'])->map(fn ($r) => (array) $r)->all())->toBe($figures);

    $this->saver->save($post->fresh(), ['title' => 'x', 'story' => facts('story') + ['figures' => [['value' => '3', 'label' => 'فروع']]]], $this->user->id);
    expect(DB::table('success_story_figures')->where('post_id', $post->id)->count())->toBe(1);

    $this->saver->save($post->fresh(), ['title' => 'x', 'story' => facts('story')], $this->user->id);   // not sent: left alone
    expect(DB::table('success_story_figures')->where('post_id', $post->id)->count())->toBe(1);

    $this->saver->save($post->fresh(), ['title' => 'x', 'story' => facts('story') + ['figures' => []]], $this->user->id);
    expect(DB::table('success_story_figures')->where('post_id', $post->id)->count())->toBe(0);
});

test('story figures are checked: a number needs its description, at most eight, blank rows ignored', function () {
    $post = $this->saver->create('story', 'قصة', $this->user->id);
    $save = fn (array $figures) => fn () => $this->saver->save($post->fresh(), ['title' => 'x', 'story' => facts('story') + ['figures' => $figures]], $this->user->id);

    expectField($save([['value' => '5', 'label' => '']]), 'story.figures');
    expectField($save(array_map(fn ($i) => ['value' => (string) $i, 'label' => 'x'], range(1, 9))), 'story.figures');
    expectField($save([['value' => str_repeat('1', 41), 'label' => 'x']]), 'story.figures');

    $save([['value' => '', 'label' => ''], ['value' => '7', 'label' => 'ماكينات']])();
    expect(DB::table('success_story_figures')->where('post_id', $post->id)->count())->toBe(1);
});

test('figures without a company are refused', function () {
    $post = $this->saver->create('story', 'قصة', $this->user->id);

    expectField(fn () => $this->saver->save($post, ['title' => 'x', 'story' => ['figures' => [['value' => '1', 'label' => 'x']]]], $this->user->id), 'story.organization_id');
});

test('a company that has a story cannot be deleted', function () {
    $post = $this->saver->create('story', 'قصة', $this->user->id);
    $this->saver->save($post, ['title' => 'x', 'story' => facts('story')], $this->user->id);

    expect(fn () => DB::transaction(fn () => $this->org->delete()))->toThrow(QueryException::class);
});

test('news, reports and opinion pieces carry no kind facts and ignore any sent', function (string $kind) {
    $post = $this->saver->create($kind, 'خبر', $this->user->id);

    $this->saver->save($post, publishData('news', ['status' => 'published', $kind => ['season_id' => 1]]), $this->user->id);

    expect($post->fresh()->status)->toBe('published');
})->with(['news', 'report', 'opinion']);

test('a section kind does not need a category, a general kind does', function () {
    $story = $this->saver->create('story', 'قصة', $this->user->id);
    $this->saver->save($story, publishData('story', ['category_id' => null]), $this->user->id);
    expect($story->fresh()->status)->toBe('published');

    $news = $this->saver->create('news', 'خبر', $this->user->id);
    expectField(fn () => $this->saver->save($news, publishData('episode', ['category_id' => null]) + ['episode' => []], $this->user->id), 'category_id');
});

test('publishing moves between the two states without ever leaving a published item without its facts', function () {
    $post = $this->saver->create('episode', 'حلقة', $this->user->id);
    $this->saver->save($post, publishData('episode'), $this->user->id);

    // Even straight in the database, the facts cannot be taken away from a published item.
    expect(fn () => DB::transaction(function () use ($post) {
        DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');
        DB::table('podcast_episodes')->where('post_id', $post->id)->delete();
        DB::table('posts')->where('id', $post->id)->update(['title' => 'تعديل']);
        DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');
    }))->toThrow(QueryException::class);
});
