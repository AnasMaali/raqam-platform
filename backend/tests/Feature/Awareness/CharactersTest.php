<?php

use App\Awareness\CharacterCatalog;
use App\Awareness\CharacterLibrary;
use App\Awareness\CharacterRejected;
use App\Awareness\ContrastCheck;
use App\Filament\Resources\Characters\Pages\CreateCharacter;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\SendSiteWebhook;
use App\Media\MediaUploader;
use App\Models\Character;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class]);
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->library = app(CharacterLibrary::class);
});

function poseImage(): int
{
    // Each picture its own width: the library keeps identical files once, so two pictures that happened to be equal
    // would be one, and "a new pose picture" would change nothing (a random width did that 1 time in 90).
    static $n = 0;

    return app(MediaUploader::class)->store(uploadFile(imageBytes(200 + ++$n, 150)), 'p.jpg', ['alt_text' => 'وضعية'], null)->asset->id;
}

function riveFile(): int
{
    return app(MediaUploader::class)->store(uploadFile('RIVE'."\x07\x00\x01".random_bytes(300)), 'c.riv', [], null)->asset->id;
}

function aCharacter(array $over = []): Character
{
    static $n = 0;   // names and addresses are unique: counted, never drawn at random
    $n++;

    return Character::query()->create($over + ['name_ar' => 'شخصية '.$n, 'slug' => 'c-'.$n, 'role_ar' => 'دور', 'accent_color' => '#0B5FA5']);
}

test('the installer adds the eight characters with their first names, readable colours, and never overwrites (7.1 - 7.8)', function () {
    expect(Artisan::call('characters:install'))->toBe(0);

    $names = Character::query()->orderBy('sort_order')->pluck('name_ar')->all();
    expect($names)->toBe(['رَقَم', 'هل تعلم؟', 'انتبه!', 'المستشار', 'المدّخِر', 'حارس الحقوق', 'كاشف الاحتيال', 'رقم اليوم']);
    foreach (Character::all() as $c) {
        expect(ContrastCheck::passes($c->accent_color))->toBeTrue("{$c->name_ar} {$c->accent_color}")->and($c->role_ar)->not->toBe('');
    }

    Character::where('slug', 'advisor')->update(['name_ar' => 'مستشارنا', 'accent_color' => '#000000']);
    Artisan::call('characters:install');

    expect(Character::count())->toBe(8)->and(Character::where('slug', 'advisor')->value('name_ar'))->toBe('مستشارنا')
        ->and(Character::where('slug', 'advisor')->value('accent_color'))->toBe('#000000');
});

test('a default whose address was changed is not added a second time (its name is still taken)', function () {
    app(CharacterCatalog::class)->install();
    Character::where('slug', 'saver')->update(['slug' => 'my-saver']);

    app(CharacterCatalog::class)->install();

    expect(Character::count())->toBe(8);
});

test('contrast follows WCAG: black on white is 21, white on white is 1', function () {
    expect(round(ContrastCheck::ratio('#000000', '#FFFFFF'), 1))->toBe(21.0)->and(round(ContrastCheck::ratio('#FFFFFF', '#FFFFFF'), 1))->toBe(1.0)
        ->and(ContrastCheck::passes('#777777'))->toBeFalse()->and(ContrastCheck::passes('#595959'))->toBeTrue()
        ->and(ContrastCheck::isHex('#0B5FA5'))->toBeTrue()->and(ContrastCheck::isHex('0B5FA5'))->toBeFalse()->and(ContrastCheck::isHex('#GGGGGG'))->toBeFalse();
});

test('a character is created through the screen with its colour checked (7.14)', function () {
    Livewire::test(CreateCharacter::class)
        ->fillForm(['name_ar' => 'الخبير', 'role_ar' => 'يشرح', 'accent_color' => '#0B5FA5'])
        ->call('create')->assertHasNoFormErrors();

    expect(Character::sole())->name_ar->toBe('الخبير')->slug->not->toBe('');
});

test('a colour too pale for the page is refused with the contrast in the message', function (string $color) {
    $page = Livewire::test(CreateCharacter::class)->fillForm(['name_ar' => 'الخبير', 'role_ar' => 'يشرح', 'accent_color' => $color])->call('create');

    $page->assertHasFormErrors(['accent_color']);
    expect(Character::count())->toBe(0);
})->with(['#FFFF00', '#CCCCCC', '#FFFFFF', '#888888']);

test('a name cannot be used twice', function () {
    aCharacter(['name_ar' => 'المستشار']);

    Livewire::test(CreateCharacter::class)->fillForm(['name_ar' => 'المستشار', 'role_ar' => 'x', 'accent_color' => '#0B5FA5'])->call('create')->assertHasFormErrors(['name_ar']);
});

test('poses are saved one per kind with their picture and state (7.9, 7.10)', function () {
    $rive = riveFile();
    $c = aCharacter(['rive_media_id' => $rive]);
    $img = [poseImage(), poseImage(), poseImage()];

    $this->library->savePoses($c, [
        'explaining' => ['static_media_id' => $img[0], 'rive_state' => 'explain'],
        'warning' => ['static_media_id' => (string) $img[1], 'rive_state' => ''],
        'smiling' => ['static_media_id' => $img[2]],
        'pointing' => ['static_media_id' => null],
        'thinking' => [],
    ]);

    $poses = $this->library->poses($c);
    expect($poses['explaining'])->toBe(['static_media_id' => $img[0], 'rive_state' => 'explain'])
        ->and($poses['warning']['rive_state'])->toBeNull()->and($poses['pointing']['static_media_id'])->toBeNull()
        ->and(array_keys($poses))->toBe(['explaining', 'warning', 'smiling', 'pointing', 'thinking'])
        ->and($this->library->missing($c))->toBe(['تشير', 'تفكّر']);
});

test('saving the poses again replaces, never duplicates', function () {
    $c = aCharacter();
    $a = poseImage();
    $b = poseImage();
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => $a]]);
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => $b]]);

    expect(DB::table('character_poses')->where('character_id', $c->id)->count())->toBe(1)->and($this->library->poses($c)['explaining']['static_media_id'])->toBe($b);
});

test('a pose picture must be a public image of the library; a state needs a picture, a Rive file and a plain name', function (array $pose, string $expectedField) {
    $c = aCharacter();   // no Rive file
    $private = app(MediaUploader::class)->store(uploadFile(imageBytes(100, 90)), 'x.jpg', ['visibility' => 'private'], null)->asset->id;
    $pose = array_map(fn ($v) => $v === 'PRIVATE' ? $private : $v, $pose);

    try {
        $this->library->savePoses($c, ['explaining' => $pose]);
        test()->fail('Accepted.');
    } catch (CharacterRejected $e) {
        expect($e->field)->toBe($expectedField);
    }
    expect(DB::table('character_poses')->count())->toBe(0);
})->with([
    'missing picture' => [['static_media_id' => 987654], 'poses.explaining.static_media_id'],
    'private picture' => [['static_media_id' => 'PRIVATE'], 'poses.explaining.static_media_id'],
    'sql in the id' => [['static_media_id' => '1; drop table characters'], 'poses.explaining.static_media_id'],
    'state without picture' => [['static_media_id' => null, 'rive_state' => 'x'], 'poses.explaining.static_media_id'],
]);

test('a state needs the character to have a Rive file, and only plain names are accepted', function () {
    $c = aCharacter();
    $img = poseImage();

    expect(fn () => $this->library->savePoses($c, ['explaining' => ['static_media_id' => $img, 'rive_state' => 'idle']]))->toThrow(CharacterRejected::class, 'ملف Rive');

    $c->update(['rive_media_id' => riveFile()]);
    expect(fn () => $this->library->savePoses($c->refresh(), ['explaining' => ['static_media_id' => $img, 'rive_state' => '<script>']]))->toThrow(CharacterRejected::class, 'حروف إنجليزية');
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => $img, 'rive_state' => 'State Machine 1']]);
    expect($this->library->poses($c)['explaining']['rive_state'])->toBe('State Machine 1');
});

test('an unknown pose kind is ignored', function () {
    $c = aCharacter();

    $this->library->savePoses($c, ['dancing' => ['static_media_id' => poseImage()], 0 => ['static_media_id' => poseImage()]]);

    expect(DB::table('character_poses')->count())->toBe(0);
});

test('a pose that cards use cannot be removed, but its picture can be replaced', function () {
    ensurePostKinds();
    $c = aCharacter();
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => poseImage()]]);
    $pose = DB::table('character_poses')->where('character_id', $c->id)->value('id');
    $post = insertPost(['kind_code' => 'awareness', 'status' => 'draft', 'published_at' => null]);
    DB::table('awareness_cards')->insert(['post_id' => $post, 'character_pose_id' => $pose, 'awareness_type_code' => 'warning']);

    expect(fn () => $this->library->savePoses($c, ['explaining' => ['static_media_id' => null]]))->toThrow(CharacterRejected::class, 'مستعملة في 1 بطاقة');
    $new = poseImage();
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => $new]]);

    expect(DB::table('character_poses')->where('id', $pose)->value('static_media_id'))->toBe($new);
});

test('the edit screen fills the five poses and saves changes to them (7.14)', function () {
    $c = aCharacter();
    $a = poseImage();
    $b = poseImage();
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => $a]]);

    $page = Livewire::test(EditCharacter::class, ['record' => $c->id]);
    expect((int) $page->get('data.poses.explaining.static_media_id'))->toBe($a)->and($page->get('data.poses.warning.static_media_id'))->toBeNull();

    $page->fillForm(['role_ar' => 'دور جديد', 'poses.warning.static_media_id' => $b])->call('save')->assertHasNoFormErrors();

    expect($c->refresh()->role_ar)->toBe('دور جديد')->and($this->library->poses($c)['warning']['static_media_id'])->toBe($b);
});

test('a bad pose stops the whole save, the character is not half changed', function () {
    $c = aCharacter(['role_ar' => 'قديم']);

    Livewire::test(EditCharacter::class, ['record' => $c->id])
        ->fillForm(['role_ar' => 'جديد', 'poses.warning.rive_state' => 'x'])->call('save')->assertHasFormErrors();

    expect($c->refresh()->role_ar)->toBe('قديم');
});

test('the list shows what each character still lacks', function () {
    $c = aCharacter();
    $this->library->savePoses($c, ['explaining' => ['static_media_id' => poseImage()]]);

    Livewire::test(ListCharacters::class)->assertCanSeeTableRecords([$c])->assertSee('ناقص');
});

test('a Rive file is chosen from the library only', function () {
    $c = aCharacter();
    $notRive = poseImage();

    Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm(['rive_media_id' => $notRive])->call('save')->assertHasFormErrors(['rive_media_id']);
    Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm(['rive_media_id' => riveFile()])->call('save')->assertHasNoFormErrors();
});

/** Switches the site webhook on, but faked, from here on. */
function fakeSiteWebhook(): void
{
    config(['raqam.site_webhook.url' => 'https://site.example/hooks/raqam', 'raqam.site_webhook.secret' => 'test-secret-0123456789']);
    Bus::fake([GenerateMediaVariants::class, SendSiteWebhook::class]);
}

/** A visible character with a Rive file and one animated pose; the webhook is switched on only after it is set up. */
function webhookCharacter(array $over = []): Character
{
    $c = aCharacter($over + ['description' => 'وصف']);
    $c->update(['rive_media_id' => riveFile()]);
    app(CharacterLibrary::class)->savePoses($c, ['explaining' => ['static_media_id' => poseImage(), 'rive_state' => 'idle']]);
    fakeSiteWebhook();

    return $c->refresh();
}

function refreshAllSent(Character $c, string $change = 'character_updated'): Closure
{
    return fn ($job) => $job->payload['type'] === 'all' && $job->payload['change'] === $change && $job->payload['id'] === $c->id && $job->payload['paths'] === [];
}

test('any edit of a visible character or of its poses asks the site to refresh everything, once per save', function (Closure $change) {
    $c = webhookCharacter();

    Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm($change())->call('save')->assertHasNoFormErrors();

    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);
    Bus::assertDispatched(SendSiteWebhook::class, refreshAllSent($c));
})->with([
    'name' => [fn () => ['name_ar' => 'اسم جديد']],
    'slug' => [fn () => ['slug' => 'new-slug']],
    'role' => [fn () => ['role_ar' => 'دور جديد']],
    'description' => [fn () => ['description' => 'وصف جديد']],
    'colour' => [fn () => ['accent_color' => '#1F6F5B']],
    'Rive file' => [fn () => ['rive_media_id' => riveFile()]],
    'pose picture' => [fn () => ['poses.explaining.static_media_id' => poseImage()]],
    'new pose' => [fn () => ['poses.warning.static_media_id' => poseImage()]],
    'removed pose' => [fn () => ['poses.explaining.static_media_id' => null, 'poses.explaining.rive_state' => null]],
    'animation state' => [fn () => ['poses.explaining.rive_state' => 'talk']],
    'SEO text' => [fn () => ['seo' => ['meta_title' => 'عنوان بحث']]],
]);

test('changing, emptying or re-saving the SEO of a visible character: one webhook per real change', function () {
    $c = webhookCharacter();
    $edit = fn (array $seo) => Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm(['seo' => $seo])->call('save')->assertHasNoFormErrors();

    $edit(['meta_title' => 'عنوان بحث']);                     // first values: a new seo_meta row
    $edit(['meta_title' => 'عنوان بحث', 'meta_description' => 'وصف بحث']);   // the same row changes
    $edit(['meta_title' => 'عنوان بحث', 'meta_description' => 'وصف بحث']);   // nothing changes
    $edit(['meta_title' => '', 'meta_description' => '']);   // emptied: back to the automatic values

    Bus::assertDispatchedTimes(SendSiteWebhook::class, 3);
    Bus::assertNotDispatched(SendSiteWebhook::class, fn ($job) => $job->payload['change'] !== 'character_updated');
});

test('creating a visible character on the screen sends one webhook, after its poses and SEO are saved', function () {
    $picture = poseImage();
    fakeSiteWebhook();

    Livewire::test(CreateCharacter::class)
        ->fillForm(['name_ar' => 'شخصية جديدة', 'slug' => 'new-one', 'role_ar' => 'دور', 'accent_color' => '#1F6F5B',
            'poses.explaining.static_media_id' => $picture, 'seo' => ['meta_title' => 'عنوان بحث']])
        ->call('create')->assertHasNoFormErrors();

    $c = Character::query()->where('slug', 'new-one')->firstOrFail();
    expect($c->is_active)->toBeTrue()->and($c->seo_meta_id)->not->toBeNull()
        ->and(app(CharacterLibrary::class)->missing($c))->not->toContain('تشرح');
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);
    Bus::assertDispatched(SendSiteWebhook::class, refreshAllSent($c, 'character_created'));
});

test('creating a hidden character sends nothing; one created outside the screen sends its own webhook', function () {
    fakeSiteWebhook();

    Livewire::test(CreateCharacter::class)
        ->fillForm(['name_ar' => 'مخفية', 'slug' => 'hidden-one', 'role_ar' => 'دور', 'accent_color' => '#1F6F5B', 'is_active' => false, 'seo' => ['meta_title' => 'عنوان']])
        ->call('create')->assertHasNoFormErrors();
    aCharacter(['is_active' => false]);
    Bus::assertNotDispatched(SendSiteWebhook::class);

    $c = aCharacter();
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);
    Bus::assertDispatched(SendSiteWebhook::class, refreshAllSent($c, 'character_created'));
});

test('a save that changes the character, a pose and its SEO together sends one webhook; a save that changes nothing sends none', function () {
    $c = webhookCharacter();

    // The first SEO values save the character a second time (its seo_meta_id); the rename must still be seen.
    Livewire::test(EditCharacter::class, ['record' => $c->id])
        ->fillForm(['name_ar' => 'اسم جديد', 'seo' => ['meta_title' => 'عنوان بحث']])->call('save')->assertHasNoFormErrors();
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);

    Livewire::test(EditCharacter::class, ['record' => $c->id])
        ->fillForm(['name_ar' => 'اسم آخر', 'poses.warning.static_media_id' => poseImage(), 'seo' => ['meta_title' => 'عنوان آخر']])
        ->call('save')->assertHasNoFormErrors();
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 2);

    Livewire::test(EditCharacter::class, ['record' => $c->id])->call('save')->assertHasNoFormErrors();
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 2);
});

test('editing a hidden character sends nothing; hiding it in the same save sends only the hiding', function () {
    $c = webhookCharacter(['is_active' => false]);

    Livewire::test(EditCharacter::class, ['record' => $c->id])
        ->fillForm(['name_ar' => 'اسم جديد', 'poses.warning.static_media_id' => poseImage()])->call('save')->assertHasNoFormErrors();
    Bus::assertNotDispatched(SendSiteWebhook::class);

    $c->update(['is_active' => true]);
    Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm(['name_ar' => 'اسم آخر', 'is_active' => false])->call('save')->assertHasNoFormErrors();

    Bus::assertDispatchedTimes(SendSiteWebhook::class, 2);
    Bus::assertDispatched(SendSiteWebhook::class, refreshAllSent($c, 'character_shown'));
    Bus::assertDispatched(SendSiteWebhook::class, refreshAllSent($c, 'character_hidden'));
});

test('a character updated outside the screen sends its own webhook; savePoses reports whether a pose really changed', function () {
    $c = webhookCharacter();
    $picture = DB::table('character_poses')->value('static_media_id');

    $c->update(['accent_color' => '#1F6F5B']);
    Bus::assertDispatchedTimes(SendSiteWebhook::class, 1);

    expect($this->library->savePoses($c, ['explaining' => ['static_media_id' => $picture, 'rive_state' => 'idle']]))->toBeFalse()
        ->and($this->library->savePoses($c, ['explaining' => ['static_media_id' => $picture, 'rive_state' => 'run']]))->toBeTrue()
        ->and($this->library->savePoses($c, ['explaining' => ['static_media_id' => poseImage(), 'rive_state' => 'run']]))->toBeTrue()
        ->and($this->library->savePoses($c, ['smiling' => ['static_media_id' => $picture]]))->toBeTrue()
        ->and($this->library->savePoses($c, ['smiling' => ['static_media_id' => null]]))->toBeTrue()
        ->and($this->library->savePoses($c, ['smiling' => ['static_media_id' => null]]))->toBeFalse();
});

test('a value only the database refuses, on the edit screen, is a sentence and not an error page, and sends nothing', function () {
    $c = webhookCharacter();

    Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm(['name_ar' => 'اسم جديد', 'sort_order' => 99999999999])->call('save')
        ->assertHasNoErrors()->assertNotified('تعذّر الحفظ');

    expect($c->refresh()->name_ar)->not->toBe('اسم جديد');
    Bus::assertNotDispatched(SendSiteWebhook::class);
});
