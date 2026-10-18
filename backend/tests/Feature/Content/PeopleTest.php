<?php

use App\Filament\Resources\Authors\Pages\CreateAuthor;
use App\Filament\Resources\Authors\Pages\EditAuthor;
use App\Filament\Resources\Authors\Pages\ListAuthors;
use App\Filament\Resources\Guests\GuestResource;
use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\Guests\Pages\EditGuest;
use App\Filament\Resources\Guests\Pages\ListGuests;
use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Models\Author;
use App\Models\Guest;
use App\Models\GuestRole;
use App\Models\Organization;
use App\Models\User;
use App\Taxonomy\PersonLinks;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
});

// ---- links ---------------------------------------------------------------------------------------

test('social links are added, changed and removed per platform, each change audited', function () {
    $author = Author::create(['name' => 'كاتب', 'slug' => 'k']);
    $links = app(PersonLinks::class);

    $links->sync('authors', $author->id, ['facebook' => 'https://facebook.com/k', 'x' => 'https://x.com/k']);
    expect($links->load('authors', $author->id))->toBe(['facebook' => 'https://facebook.com/k', 'x' => 'https://x.com/k']);

    $links->sync('authors', $author->id, ['facebook' => 'https://facebook.com/k2', 'x' => '']);
    expect($links->load('authors', $author->id))->toBe(['facebook' => 'https://facebook.com/k2']);

    $fields = DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_id')
        ->where('subject_table', 'authors')->where('subject_id', $author->id)->orderBy('audit_id')->pluck('field')->all();
    expect($fields)->toContain('link.facebook', 'link.x');
});

test('platforms not sent are left alone, unknown platforms are ignored', function () {
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'd']);
    $links = app(PersonLinks::class);
    $links->sync('guests', $guest->id, ['facebook' => 'https://facebook.com/d']);

    $links->sync('guests', $guest->id, ['x' => 'https://x.com/d', 'myspace' => 'https://myspace.com/d']);

    expect($links->load('guests', $guest->id))->toBe(['facebook' => 'https://facebook.com/d', 'x' => 'https://x.com/d']);
});

test('a link must be a web address and nothing is saved when one is wrong', function (string $bad) {
    $author = Author::create(['name' => 'كاتب', 'slug' => 'k']);

    expect(fn () => app(PersonLinks::class)->sync('authors', $author->id, ['facebook' => 'https://facebook.com/ok', 'x' => $bad]))->toThrow(ValidationException::class);

    expect(app(PersonLinks::class)->load('authors', $author->id))->toBe([]);
})->with(['javascript:alert(1)', 'ftp://x', 'x.com/k', 'data:text/html,<script>']);

test('links of one person never leak onto another', function () {
    $a = Author::create(['name' => 'أ', 'slug' => 'a']);
    $b = Author::create(['name' => 'ب', 'slug' => 'b']);
    app(PersonLinks::class)->sync('authors', $a->id, ['facebook' => 'https://facebook.com/a']);

    expect(app(PersonLinks::class)->load('authors', $b->id))->toBe([]);
});

// ---- authors -------------------------------------------------------------------------------------

test('an author is created with a slug, a team position, links and SEO text', function () {
    Livewire::test(CreateAuthor::class)
        ->fillForm([
            'name' => 'سامي الخطيب', 'job_title' => 'محرر اقتصادي', 'bio' => 'نبذة', 'team_sort_order' => 2, 'is_active' => true,
            'links' => ['facebook' => 'https://facebook.com/sami'], 'seo' => ['meta_title' => 'سامي الخطيب - رَقَم'],
        ])->call('create')->assertHasNoFormErrors();

    $author = Author::where('name', 'سامي الخطيب')->sole();
    expect($author)->slug->toBe('سامي-الخطيب')->team_sort_order->toBe(2)->and($author->seoMeta->meta_title)->toBe('سامي الخطيب - رَقَم');
    expect(app(PersonLinks::class)->load('authors', $author->id))->toBe(['facebook' => 'https://facebook.com/sami']);
});

test('editing an author shows the saved links and SEO and saves changes to them', function () {
    $author = Author::create(['name' => 'كاتب', 'slug' => 'k']);
    app(PersonLinks::class)->sync('authors', $author->id, ['x' => 'https://x.com/k']);

    Livewire::test(EditAuthor::class, ['record' => $author->id])
        ->assertSet('data.links.x', 'https://x.com/k')
        ->fillForm(['name' => 'كاتب مُعدَّل', 'links' => ['x' => 'https://x.com/k2']])
        ->call('save')->assertHasNoFormErrors();

    expect($author->fresh()->name)->toBe('كاتب مُعدَّل')->and(app(PersonLinks::class)->load('authors', $author->id))->toBe(['x' => 'https://x.com/k2']);
});

test('a wrong link in the form shows an error and saves nothing of the form', function () {
    $author = Author::create(['name' => 'كاتب', 'slug' => 'k']);

    Livewire::test(EditAuthor::class, ['record' => $author->id])
        ->fillForm(['name' => 'لن يُحفظ', 'links' => ['x' => 'javascript:alert(1)']])
        ->call('save')->assertHasFormErrors();

    expect($author->fresh()->name)->toBe('كاتب');
});

test('the team position must be a whole number between 0 and 32767, or empty', function (mixed $value) {
    Livewire::test(CreateAuthor::class)->fillForm(['name' => 'س', 'team_sort_order' => $value, 'is_active' => true])->call('create')->assertHasFormErrors(['team_sort_order']);
})->with([-1, 40000, 'abc']);

test('two authors with the same name get different addresses', function () {
    foreach ([1, 2] as $_) {
        Livewire::test(CreateAuthor::class)->fillForm(['name' => 'محمد علي', 'is_active' => true])->call('create')->assertHasNoFormErrors();
    }

    expect(Author::pluck('slug')->sort()->values()->all())->toBe(['محمد-علي', 'محمد-علي-2']);
});

test('deleting an author keeps the record and its articles, and can be undone', function () {
    $author = Author::create(['name' => 'كاتب', 'slug' => 'k']);
    $post = insertPost();
    DB::table('post_authors')->insert(['post_id' => $post, 'author_id' => $author->id, 'sort_order' => 0]);

    Livewire::test(ListAuthors::class)->callAction(TestAction::make('delete')->table($author));
    expect(Author::count())->toBe(0)->and(Author::withTrashed()->count())->toBe(1)->and(DB::table('post_authors')->where('author_id', $author->id)->exists())->toBeTrue();

    Livewire::test(ListAuthors::class)->filterTable('trashed', true)->callAction(TestAction::make('restore')->table(Author::onlyTrashed()->first()));
    expect(Author::count())->toBe(1);
});

test('the list finds an author by name ignoring hamza and diacritics', function () {
    Author::create(['name' => 'أحمد', 'slug' => 'a']);
    Author::create(['name' => 'ليلى', 'slug' => 'l']);

    $found = Livewire::test(ListAuthors::class)->searchTable('احمد')->instance()->getTableRecords()->pluck('name')->all();

    expect($found)->toBe(['أحمد']);
});

test('the list says that a piece without a writer is shown as the team', function () {
    Livewire::test(ListAuthors::class)->assertSee('فريق رَقَم');
});

// ---- guests --------------------------------------------------------------------------------------

test('the three roles a guest can play are in the database', function () {
    expect(GuestRole::options())->toBe(['guest' => 'ضيف', 'host' => 'مقدّم', 'panelist' => 'مشارك']);
});

test('a guest is created once with links, an organisation and SEO text', function () {
    $org = Organization::create(['name' => 'شركة الأمل', 'slug' => 'amal']);

    Livewire::test(CreateGuest::class)
        ->fillForm(['name' => 'ليلى حسن', 'job_title' => 'مديرة', 'organization_id' => $org->id, 'links' => ['x' => 'https://x.com/layla'], 'seo' => ['meta_title' => 'ليلى حسن']])
        ->call('create')->assertHasNoFormErrors();

    $guest = Guest::where('name', 'ليلى حسن')->sole();
    expect($guest)->slug->toBe('ليلى-حسن')->organization_id->toBe($org->id)->and($guest->organization->name)->toBe('شركة الأمل');
    expect(app(PersonLinks::class)->load('guests', $guest->id))->toBe(['x' => 'https://x.com/layla']);
});

test('a new organisation can be added from inside the guest form', function () {
    $component = Livewire::test(CreateGuest::class)->fillForm(['name' => 'ضيف'])
        ->callFormComponentAction('organization_id', 'createOption', ['name' => 'مصنع النور', 'website' => 'https://noor.example']);

    $org = Organization::where('name', 'مصنع النور')->sole();
    expect($org->slug)->toBe('مصنع-النور')->and($org->website)->toBe('https://noor.example');
});

test('an organisation with the same name gets its own address', function () {
    Organization::create(['name' => 'النور', 'slug' => 'النور']);

    Livewire::test(CreateGuest::class)->fillForm(['name' => 'ضيف'])
        ->callFormComponentAction('organization_id', 'createOption', ['name' => 'النور']);

    expect(Organization::pluck('slug')->sort()->values()->all())->toBe(['النور', 'النور-2']);
});

test('a guest page lists every item the guest appeared in, with the part played', function () {
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'd']);
    $one = insertPost(['title' => 'حلقة الذهب']);
    $two = insertPost(['title' => 'جلسة الطاقة']);
    DB::table('post_guests')->insert([
        ['post_id' => $one, 'guest_id' => $guest->id, 'role_code' => 'guest'],
        ['post_id' => $two, 'guest_id' => $guest->id, 'role_code' => 'panelist'],
    ]);

    expect(GuestResource::appearances($guest))->toBe(['جلسة الطاقة — مشارك', 'حلقة الذهب — ضيف']);
    Livewire::test(EditGuest::class, ['record' => $guest->id])->assertSee('حلقة الذهب — ضيف');
});

test('a guest with no appearances says so', function () {
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'd']);

    expect(GuestResource::appearances($guest))->toBe([]);
    Livewire::test(EditGuest::class, ['record' => $guest->id])->assertSee('لم يظهر في أي مادة بعد');
});

test('deleting a guest hides them but keeps the appearances, and they can come back', function () {
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'd']);
    DB::table('post_guests')->insert(['post_id' => insertPost(), 'guest_id' => $guest->id, 'role_code' => 'host']);

    Livewire::test(ListGuests::class)->callAction(TestAction::make('delete')->table($guest));
    expect(Guest::count())->toBe(0)->and(DB::table('post_guests')->where('guest_id', $guest->id)->exists())->toBeTrue();

    Livewire::test(ListGuests::class)->filterTable('trashed', true)->callAction(TestAction::make('restore')->table(Guest::onlyTrashed()->first()));
    expect(Guest::count())->toBe(1);
});

test('a guest photo from the library is protected from the trash', function () {
    Storage::fake('media_local_public');
    Bus::fake();
    $media = app(MediaUploader::class)->store(uploadFile(imageBytes(150, 100)), 'g.jpg', ['alt_text' => 'x'], null)->asset;

    Livewire::test(CreateGuest::class)->fillForm(['name' => 'ضيف بصورة', 'photo_media_id' => $media->id])->call('create')->assertHasNoFormErrors();

    expect(fn () => app(MediaTrash::class)->trash($media))->toThrow(MediaInUse::class);
});

// ---- forged values -------------------------------------------------------------------------------

test('a photo must be a live public picture of the library, whatever the form sent', function (string $kind) {
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    Bus::fake();
    $uploader = app(MediaUploader::class);
    $private = $uploader->store(uploadFile(imageBytes(160, 100)), 'p.jpg', ['visibility' => 'private'], null)->asset;
    $pdf = $uploader->store(uploadFile("%PDF-1.4\n%%EOF"), 'r.pdf', [], null)->asset;
    $trashed = $uploader->store(uploadFile(imageBytes(161, 100)), 't.jpg', ['alt_text' => 'x'], null)->asset;
    app(MediaTrash::class)->trash($trashed);

    $value = ['private' => $private->id, 'pdf' => $pdf->id, 'trashed' => $trashed->id, 'missing' => 987654, 'text' => 'abc', 'huge' => '99999999999999999999999'][$kind];

    Livewire::test(CreateAuthor::class)->fillForm(['name' => 'س', 'is_active' => true])->set('data.photo_media_id', $value)
        ->call('create')->assertHasFormErrors(['photo_media_id']);

    expect(Author::count())->toBe(0);
})->with(['private', 'pdf', 'trashed', 'missing', 'text', 'huge']);

test('an organisation that does not exist is reported, not an error page', function () {
    $page = new CreateGuest;
    $create = new ReflectionMethod($page, 'handleRecordCreation');

    expect(fn () => $create->invoke($page, ['name' => 'ضيف', 'slug' => 'd', 'organization_id' => 987654]))->toThrow(Halt::class);

    expect(Guest::count())->toBe(0);
});
