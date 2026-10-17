<?php

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\MediaAssets\Pages\CreateMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Jobs\GenerateMediaVariants;
use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Models\MediaAsset;
use App\Models\User;
use App\Settings\SettingsStore;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->url = '/'.config('raqam.admin_path').'/media-assets';
});

function liveUpload(string $bytes, string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function libraryAsset(int $w = 300, int $h = 200, array $attrs = []): MediaAsset
{
    return app(MediaUploader::class)->store(uploadFile(imageBytes($w, $h)), 'p.jpg', $attrs + ['alt_text' => 'وصف'], null)->asset;
}

test('the library is in the menu and only for signed-in staff', function () {
    $this->followingRedirects()->get('/'.config('raqam.admin_path'))->assertSee($this->url, false);

    auth()->logout();
    $this->get($this->url)->assertRedirect();
    $this->actingAs(User::factory()->create())->get($this->url)->assertForbidden();
});

test('uploading through the form stores the file, and the alt text is required', function () {
    Bus::fake([GenerateMediaVariants::class]);

    Livewire::test(CreateMediaAsset::class)
        ->fillForm(['file' => liveUpload(imageBytes(400, 300)), 'visibility' => 'public', 'alt_text' => 'سوق نابلس'])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = MediaAsset::sole();
    expect($asset->alt_text)->toBe('سوق نابلس')->and($asset->uploaded_by)->toBe($this->admin->id)->and($asset->width)->toBe(400);
});

test('an upload without alt text shows an Arabic error under that field and stores nothing', function () {
    Livewire::test(CreateMediaAsset::class)
        ->fillForm(['file' => liveUpload(imageBytes(400, 300)), 'visibility' => 'public', 'alt_text' => ''])
        ->call('create')
        ->assertHasFormErrors(['alt_text']);

    expect(MediaAsset::count())->toBe(0)->and(Storage::disk('media_local_public')->allFiles())->toBe([]);
});

test('a disguised script is refused by the form with a message under the file field', function () {
    Livewire::test(CreateMediaAsset::class)
        ->fillForm(['file' => liveUpload("<?php echo 'x';", 'pic.jpg'), 'visibility' => 'public', 'alt_text' => 'x'])
        ->call('create')
        ->assertHasFormErrors(['file']);

    expect(MediaAsset::count())->toBe(0);
});

test('uploading the same file twice says so and opens the first one', function () {
    $first = libraryAsset();
    $bytes = Storage::disk('media_local_public')->get($first->path);   // the stored bytes re-encode to themselves
    Bus::fake();

    Livewire::test(CreateMediaAsset::class)
        ->fillForm(['file' => liveUpload($bytes), 'visibility' => 'public', 'alt_text' => 'x'])
        ->call('create');

    expect(MediaAsset::count())->toBe(1);
});

test('the list shows files, searches them and filters by type and visibility', function () {
    $photo = libraryAsset(300, 200, ['alt_text' => 'قلعة نابلس']);
    $other = libraryAsset(301, 200, ['alt_text' => 'سوق']);
    $pdf = app(MediaUploader::class)->store(uploadFile("%PDF-1.4\n%%EOF"), 'report.pdf', [], null)->asset;
    $secret = app(MediaUploader::class)->store(uploadFile(imageBytes(302, 200)), 'secret.jpg', ['visibility' => 'private'], null)->asset;

    Livewire::test(ListMediaAssets::class)
        ->assertCanSeeTableRecords([$photo, $other, $pdf, $secret])
        ->searchTable('قلعة')->assertCanSeeTableRecords([$photo])->assertCanNotSeeTableRecords([$other, $pdf])
        ->searchTable('')
        ->filterTable('type', 'pdf')->assertCanSeeTableRecords([$pdf])->assertCanNotSeeTableRecords([$photo])
        ->filterTable('type', 'image')->assertCanSeeTableRecords([$photo, $other, $secret])->assertCanNotSeeTableRecords([$pdf])
        ->resetTableFilters()
        ->filterTable('visibility', 'private')->assertCanSeeTableRecords([$secret])->assertCanNotSeeTableRecords([$photo]);
});

test('the list hides trashed files until the trash filter is on', function () {
    $asset = libraryAsset();
    app(MediaTrash::class)->trash($asset);

    Livewire::test(ListMediaAssets::class)
        ->assertCanNotSeeTableRecords([$asset])
        ->filterTable('trashed', true)->assertCanSeeTableRecords(MediaAsset::onlyTrashed()->get());
});

test('trashing from the list works for an unused file and explains why not for a used one', function () {
    $free = libraryAsset(300, 200);
    $used = libraryAsset(301, 200);
    DB::table('authors')->insert(['name' => 'كاتب', 'slug' => 'k', 'photo_media_id' => $used->id]);

    Livewire::test(ListMediaAssets::class)
        ->callAction(TestAction::make('trash')->table($free))
        ->callAction(TestAction::make('trash')->table($used))
        ->assertNotified();

    expect($free->fresh()->trashed())->toBeTrue()->and($used->fresh()->trashed())->toBeFalse();
});

test('a trashed file can be restored and then deleted for good from the screen', function () {
    $asset = libraryAsset();
    app(MediaTrash::class)->trash($asset);
    $trashed = MediaAsset::onlyTrashed()->find($asset->id);

    Livewire::test(ListMediaAssets::class)->filterTable('trashed', true)
        ->callAction(TestAction::make('restore')->table($trashed));
    expect($asset->fresh()->trashed())->toBeFalse();

    app(MediaTrash::class)->trash($asset);
    Livewire::test(ListMediaAssets::class)->filterTable('trashed', true)
        ->callAction(TestAction::make('purge')->table(MediaAsset::onlyTrashed()->find($asset->id)));

    expect(MediaAsset::withTrashed()->count())->toBe(0);
});

test('the edit screen changes the alt text, caption and credit, and says where the file is used', function () {
    $asset = libraryAsset();
    DB::table('authors')->insert(['name' => 'كاتب', 'slug' => 'k', 'photo_media_id' => $asset->id]);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->id])
        ->assertSee('صورة كاتب')
        ->fillForm(['alt_text' => 'نص جديد', 'caption' => 'تعليق', 'credit' => 'مصور'])
        ->call('save')->assertHasNoFormErrors();

    expect($asset->fresh())->alt_text->toBe('نص جديد')->caption->toBe('تعليق')->credit->toBe('مصور');
});

test('the alt text cannot be emptied on a public image', function () {
    $asset = libraryAsset();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->id])
        ->fillForm(['alt_text' => ''])->call('save')->assertHasFormErrors(['alt_text' => 'required']);

    expect($asset->fresh()->alt_text)->toBe('وصف');
});

test('the focal point is saved from a click, kept between 0 and 1, and cut crops are rebuilt', function () {
    $asset = libraryAsset(600, 300);
    Bus::fake([GenerateMediaVariants::class]);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->id])
        ->fillForm(['focal' => ['x' => 0.25, 'y' => 0.8]])
        ->call('save')->assertHasNoFormErrors();

    expect($asset->fresh())->focal_x->toBe(0.25)->focal_y->toBe(0.8);
    Bus::assertDispatched(GenerateMediaVariants::class);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->id])
        ->fillForm(['focal' => ['x' => 7, 'y' => -3]])->call('save');
    expect($asset->fresh())->focal_x->toBe(1.0)->focal_y->toBe(0.0);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->id])
        ->assertSet('data.focal', ['x' => 1.0, 'y' => 0.0])
        ->fillForm(['focal' => null])->call('save');
    expect($asset->fresh())->focal_x->toBeNull()->focal_y->toBeNull();
});

test('the edit screen cannot change what the file is: type, path, size, disk or checksum', function () {
    $asset = libraryAsset();
    $before = $asset->only(['mime_type', 'path', 'size_bytes', 'disk', 'checksum_sha256', 'visibility']);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->id])
        ->fillForm(['alt_text' => 'x'])
        ->set('data.mime_type', 'text/html')->set('data.path', '../../x.php')->set('data.visibility', 'private')
        ->call('save');

    expect($asset->fresh()->only(array_keys($before)))->toBe($before);
});

test('the media library resource has no plain delete, only the trash action', function () {
    expect(MediaAssetResource::canDelete(libraryAsset()))->toBeFalse()->and(MediaAssetResource::canDeleteAny())->toBeFalse();
    $this->get($this->url.'/not-a-number/edit')->assertNotFound();
});

test('logo and default share image are picked from public images only', function () {
    $public = libraryAsset();
    $private = app(MediaUploader::class)->store(uploadFile(imageBytes(310, 200)), 's.jpg', ['visibility' => 'private'], null)->asset;
    $pdf = app(MediaUploader::class)->store(uploadFile("%PDF-1.4\n%%EOF"), 'r.pdf', [], null)->asset;
    $store = app(SettingsStore::class);

    $store->save(['general.logo' => (string) $public->id], [], $this->admin->id);
    expect($store->values()['general.logo'])->toBe((string) $public->id);

    foreach ([$private->id, $pdf->id, 99999] as $bad) {
        expect(fn () => $store->save(['general.logo' => (string) $bad], [], $this->admin->id))->toThrow(ValidationException::class);
    }
    expect($store->values()['general.logo'])->toBe((string) $public->id);
});

test('a file used as the logo cannot be trashed', function () {
    $logo = libraryAsset();
    app(SettingsStore::class)->save(['general.logo' => (string) $logo->id], [], $this->admin->id);

    expect(fn () => app(MediaTrash::class)->trash($logo))->toThrow(MediaInUse::class);
});
