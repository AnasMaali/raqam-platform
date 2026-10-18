<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\SeoMeta;
use App\Models\User;
use App\Seo\SeoMetaSaver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->staff()->create());
    $this->saver = app(SeoMetaSaver::class);
    $this->category = Category::where('slug', 'طاقة')->first();
});

test('values are saved in a seo_meta row linked to the record', function () {
    $this->saver->sync($this->category, ['meta_title' => 'أخبار الطاقة', 'meta_description' => 'وصف', 'canonical_url' => 'https://raqam.ps/طاقة', 'robots_noindex' => true]);

    $meta = $this->category->fresh()->seoMeta;
    expect($meta)->meta_title->toBe('أخبار الطاقة')->meta_description->toBe('وصف')->robots_noindex->toBeTrue()
        ->and($this->category->fresh()->seo_meta_id)->toBe($meta->id);
});

test('saving again updates the same row instead of making another', function () {
    $this->saver->sync($this->category, ['meta_title' => 'أول']);
    $first = $this->category->fresh()->seo_meta_id;

    $this->saver->sync($this->category->fresh(), ['meta_title' => 'ثان']);

    expect($this->category->fresh()->seo_meta_id)->toBe($first)->and(SeoMeta::count())->toBe(1)->and(SeoMeta::first()->meta_title)->toBe('ثان');
});

test('leaving every field empty keeps no row at all, and clearing removes the one that was there', function () {
    $this->saver->sync($this->category, ['meta_title' => '   ', 'meta_description' => '', 'robots_noindex' => false]);
    expect(SeoMeta::count())->toBe(0)->and($this->category->fresh()->seo_meta_id)->toBeNull();

    $this->saver->sync($this->category, ['meta_title' => 'عنوان']);
    $this->saver->sync($this->category->fresh(), ['meta_title' => '']);

    expect(SeoMeta::count())->toBe(0)->and($this->category->fresh()->seo_meta_id)->toBeNull();
});

test('the title and the description are limited to what search results show', function () {
    expect(fn () => $this->saver->sync($this->category, ['meta_title' => str_repeat('ا', 71)]))->toThrow(ValidationException::class);
    expect(fn () => $this->saver->sync($this->category, ['meta_description' => str_repeat('ا', 171)]))->toThrow(ValidationException::class);

    $this->saver->sync($this->category, ['meta_title' => str_repeat('ا', 70), 'meta_description' => str_repeat('ا', 170)]);
    expect(SeoMeta::count())->toBe(1);
});

test('a canonical address must be a web address', function (string $url) {
    expect(fn () => $this->saver->sync($this->category, ['canonical_url' => $url]))->toThrow(ValidationException::class);
})->with(['javascript:alert(1)', 'ftp://x.com/a', 'not a url', 'data:text/html,x']);

test('the share image must be a public picture from the library', function () {
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    Bus::fake();
    $upload = fn (array $attrs, string $bytes) => app(MediaUploader::class)->store(uploadFile($bytes), 'a', $attrs, null)->asset;
    $public = $upload(['alt_text' => 'x'], imageBytes(100, 80));
    $private = $upload(['visibility' => 'private'], imageBytes(101, 80));
    $pdf = $upload([], "%PDF-1.4\n%%EOF");

    $this->saver->sync($this->category, ['share_media_id' => $public->id]);
    expect($this->category->fresh()->seoMeta->share_media_id)->toBe($public->id);

    foreach ([$private->id, $pdf->id, 99999999] as $bad) {
        expect(fn () => $this->saver->sync($this->category->fresh(), ['share_media_id' => $bad]))->toThrow(ValidationException::class);
    }
});

test('a share image used here cannot be trashed from the library', function () {
    Storage::fake('media_local_public');
    Bus::fake();
    $media = app(MediaUploader::class)->store(uploadFile(imageBytes(120, 80)), 'a.jpg', ['alt_text' => 'x'], null)->asset;
    $this->saver->sync($this->category, ['share_media_id' => $media->id]);

    expect(fn () => app(MediaTrash::class)->trash($media))->toThrow(MediaInUse::class);
});

test('changes are audited with who changed what', function () {
    $this->saver->sync($this->category, ['meta_title' => 'عنوان']);
    $this->saver->sync($this->category->fresh(), ['meta_title' => 'عنوان جديد']);

    $rows = DB::table('audit_log')->where('subject_table', 'seo_meta')->orderBy('id')->pluck('action')->all();
    expect($rows)->toBe(['create', 'update']);
});

test('the form loads what is stored and a record without overrides loads nothing', function () {
    expect($this->saver->load($this->category))->toBe([]);

    $this->saver->sync($this->category, ['meta_title' => 'عنوان', 'robots_noindex' => true]);

    expect($this->saver->load($this->category->fresh()))->toMatchArray(['meta_title' => 'عنوان', 'robots_noindex' => true, 'meta_description' => null]);
});

test('the database refuses what the saver would also refuse', function () {
    expect(fn () => DB::transaction(fn () => DB::table('seo_meta')->insert(['meta_title' => str_repeat('ا', 71)])))->toThrow(QueryException::class);
});

test('SEO fields in a form: saved with the record, errors show under the field, nothing is half saved', function () {
    Filament\Facades\Filament::setCurrentPanel('admin');

    Livewire\Livewire::test(EditCategory::class, ['record' => $this->category->id])
        ->fillForm(['seo' => ['meta_title' => 'عنوان بحث', 'meta_description' => 'وصف بحث']])
        ->call('save')->assertHasNoFormErrors();
    expect($this->category->fresh()->seoMeta->meta_title)->toBe('عنوان بحث');

    Livewire\Livewire::test(EditCategory::class, ['record' => $this->category->id])
        ->assertSet('data.seo.meta_title', 'عنوان بحث')
        ->fillForm(['name_ar' => 'اسم يجب ألا يُحفظ', 'seo' => ['meta_title' => str_repeat('ا', 80)]])
        ->call('save')->assertHasFormErrors();

    expect($this->category->fresh()->name_ar)->toBe('طاقة')->and($this->category->fresh()->seoMeta->meta_title)->toBe('عنوان بحث');
});

test('a share image that is not a public library picture is refused with a message under the field', function () {
    Filament\Facades\Filament::setCurrentPanel('admin');

    Livewire\Livewire::test(EditCategory::class, ['record' => $this->category->id])
        ->fillForm(['name_ar' => 'اسم يجب ألا يُحفظ'])
        ->set('data.seo.share_media_id', 424242)
        ->call('save')->assertHasErrors(['data.seo.share_media_id']);

    // Refused before anything was written: the record keeps its name.
    expect(MediaAsset::count())->toBe(0)->and($this->category->fresh()->name_ar)->toBe('طاقة');
});
