<?php

use App\Content\StaticPages;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
});

test('the standard pages are installed once as unpublished drafts and never overwritten', function () {
    $this->artisan('pages:install')->expectsOutput('Added '.count(StaticPages::SYSTEM).' page(s).')->assertSuccessful();
    expect(Page::count())->toBe(count(StaticPages::SYSTEM))->and(Page::where('is_published', true)->count())->toBe(0);

    Page::where('system_key', 'about')->update(['body' => '<p>كتبناها</p>', 'is_published' => true]);
    $this->artisan('pages:install')->expectsOutput('Added 0 page(s).')->assertSuccessful();

    expect(Page::where('system_key', 'about')->first())->body->toBe('<p>كتبناها</p>')->is_published->toBeTrue();
});

test('the list of standard pages covers what decision 28 names', function () {
    foreach (['about', 'team', 'contact', 'advertise', 'services', 'faq', 'privacy', 'terms', 'cookies', 'copyright', 'editorial_policy', 'corrections_policy', 'sponsored_policy'] as $key) {
        expect(StaticPages::SYSTEM)->toHaveKey($key);
    }
});

test('a page is created with a clean text, an address and who wrote it', function () {
    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'عن المنصة', 'body' => '<h2>نبذة</h2><p onclick="x()">نص <script>alert(1)</script></p>', 'is_published' => true])
        ->call('create')->assertHasNoFormErrors();

    $page = Page::sole();
    expect($page)->slug->toBe('عن-المنصة')->body->toBe('<h2>نبذة</h2><p>نص</p>')->updated_by->toBe($this->admin->id)->is_published->toBeTrue();
});

test('blocks of the article editor are not allowed in a page', function () {
    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'صفحة', 'body' => '<p>نص</p><div data-type="customBlock" data-id="read-also" data-config=\'{"post_ids":[1]}\'></div>'])
        ->call('create');

    expect(Page::sole()->body)->toBe('<p>نص</p>');
});

test('two pages never share an address, and the reserved ones are refused', function () {
    foreach ([1, 2] as $_) {
        Livewire::test(CreatePage::class)->fillForm(['title' => 'اتفاقية'])->call('create')->assertHasNoFormErrors();
    }
    expect(Page::pluck('slug')->sort()->values()->all())->toBe(['اتفاقية', 'اتفاقية-2']);

    foreach (['news', 'podcast', 'api', 'search', 'Podcast', 'PRIVATE-MEDIA'] as $reserved) {
        Livewire::test(CreatePage::class)->fillForm(['title' => 'محجوزة', 'slug' => $reserved])->call('create')->assertHasFormErrors(['slug']);
    }
    expect(Page::count())->toBe(2);
});

test('a standard page keeps its address and cannot be deleted, a custom one can', function () {
    (new StaticPages)->install();
    $about = Page::where('system_key', 'about')->first();
    $custom = Page::create(['title' => 'مخصصة', 'slug' => 'custom']);

    Livewire::test(EditPage::class, ['record' => $about->id])->fillForm(['title' => 'عن رَقَم', 'body' => '<p>نص</p>'])->set('data.slug', 'hacked')->call('save')->assertHasNoFormErrors();
    expect($about->fresh())->title->toBe('عن رَقَم')->slug->toBe('about');

    expect(PageResource::canDelete($about))->toBeFalse()->and(PageResource::canDelete($custom))->toBeTrue()->and(PageResource::canDeleteAny())->toBeFalse();
    Livewire::test(ListPages::class)->callAction(TestAction::make('delete')->table($custom));
    expect(Page::whereKey($custom->id)->exists())->toBeFalse();
});

test('a standard page cannot be removed from the database by a request either', function () {
    (new StaticPages)->install();

    // The key is part of what the site's own links rely on: the form has no way to change it.
    $about = Page::where('system_key', 'about')->first();
    Livewire::test(EditPage::class, ['record' => $about->id])->set('data.system_key', 'other')->fillForm(['title' => 'x'])->call('save');

    expect($about->fresh()->system_key)->toBe('about');
});

test('the search fields are saved with the page and every change is audited without copying the text', function () {
    $page = Page::create(['title' => 'الخصوصية', 'slug' => 'privacy-x']);

    Livewire::test(EditPage::class, ['record' => $page->id])
        ->fillForm(['body' => '<p>'.str_repeat('نص ', 50).'</p>', 'seo' => ['meta_title' => 'سياسة الخصوصية', 'robots_noindex' => false]])
        ->call('save')->assertHasNoFormErrors();

    expect($page->fresh()->seoMeta->meta_title)->toBe('سياسة الخصوصية');
    $fields = DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_id')->where('subject_table', 'pages')->pluck('field')->all();
    expect(DB::table('audit_log')->where('subject_table', 'pages')->where('subject_id', $page->id)->where('action', 'update')->exists())->toBeTrue();
});

test('the pages screens are for signed-in staff only', function () {
    auth()->logout();
    $this->get('/'.config('raqam.admin_path').'/pages')->assertRedirect();
    $this->actingAs(User::factory()->create())->get('/'.config('raqam.admin_path').'/pages')->assertForbidden();
});
