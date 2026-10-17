<?php

use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Media\MediaUsage;
use App\Media\VariantGenerator;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake();
    $this->asset = app(MediaUploader::class)->store(uploadFile(imageBytes(200, 100)), 'a.jpg', ['alt_text' => 'x'], null)->asset;
    $this->trash = app(MediaTrash::class);
    $this->usage = app(MediaUsage::class);
});

function useAsAuthorPhoto(int $mediaId): int
{
    return DB::table('authors')->insertGetId(['name' => 'كاتب', 'slug' => 'k-'.$mediaId, 'photo_media_id' => $mediaId]);
}

test('an unused file goes to the trash and comes back', function () {
    $this->trash->trash($this->asset);
    expect($this->asset->fresh()->trashed())->toBeTrue()->and(MediaAsset::count())->toBe(0);

    $this->trash->restore(MediaAsset::withTrashed()->find($this->asset->id));
    expect(MediaAsset::count())->toBe(1);
});

test('a file used by a post cover cannot go to the trash', function () {
    insertPost(['cover_media_id' => $this->asset->id]);

    expect(fn () => $this->trash->trash($this->asset))->toThrow(MediaInUse::class);
    expect($this->asset->fresh()->trashed())->toBeFalse();
});

test('every place a file can be used stops the trash, whichever table holds the reference', function (string $table, array $row, string $column) {
    if ($table === 'sections') {
        DB::table('sections')->where('key', 'podcast')->update([$column => $this->asset->id]);   // the seven sections are part of the seed
    } else {
        DB::table($table)->insert($row + [$column => $this->asset->id]);
    }

    expect(fn () => $this->trash->trash($this->asset))->toThrow(MediaInUse::class);
})->with([
    'author photo' => ['authors', ['name' => 'ك', 'slug' => 'ك-1'], 'photo_media_id'],
    'guest photo' => ['guests', ['name' => 'ض', 'slug' => 'ض-1'], 'photo_media_id'],
    'organization logo' => ['organizations', ['name' => 'جهة', 'slug' => 'jiha'], 'logo_media_id'],
    'section cover' => ['sections', [], 'cover_media_id'],
]);

test('the logo or default share image saved in settings also stops the trash', function () {
    DB::table('settings')->insert(['key' => 'general.logo', 'value' => (string) $this->asset->id, 'value_type' => 'media']);

    expect(fn () => $this->trash->trash($this->asset))->toThrow(MediaInUse::class);
    expect($this->usage->of($this->asset))->toBe([['label' => 'إعدادات الموقع', 'id' => 'general.logo']]);
});

test('a setting that merely holds the same number as text does not count', function () {
    DB::table('settings')->insert(['key' => 'general.tagline', 'value' => (string) $this->asset->id, 'value_type' => 'string']);

    $this->trash->trash($this->asset);

    expect($this->asset->fresh()->trashed())->toBeTrue();
});

test('the message names where the file is used', function () {
    $author = useAsAuthorPhoto($this->asset->id);

    try {
        $this->trash->trash($this->asset);
    } catch (MediaInUse $e) {
        expect($e->getMessage())->toContain('صورة كاتب')->toContain("id={$author}")->toContain('مستخدمة');
    }
});

test('the usage list shows the place and the row, for several places at once', function () {
    $post = insertPost(['cover_media_id' => $this->asset->id]);
    $author = useAsAuthorPhoto($this->asset->id);

    $usages = collect($this->usage->of($this->asset))->map(fn ($u) => "{$u['label']}|{$u['id']}")->all();

    expect($usages)->toContain("الصورة الرئيسية لمادة|id={$post}")->toContain("صورة كاتب|id={$author}");
});

test('a file that is not used has an empty usage list', function () {
    expect($this->usage->of($this->asset))->toBe([]);
});

test('the places are found from the database itself, so a table added later is covered', function () {
    $columns = collect(DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS t, a.attname AS c FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE c.contype = 'f' AND c.confrelid = 'media_assets'::regclass AND c.conrelid <> 'media_variants'::regclass
        SQL))->map(fn ($r) => "{$r->t}.{$r->c}");

    $labelled = (new ReflectionClassConstant(MediaUsage::class, 'LABELS'))->getValue();

    // Every reference has a friendly name; a new one would fail here and remind us to name it.
    expect($columns->reject(fn ($c) => isset($labelled[$c]))->values()->all())->toBe([]);
});

test('a file in the trash can be deleted for good with its renditions, a live one cannot', function () {
    Storage::fake('media_local_public');
    $asset = app(MediaUploader::class)->store(uploadFile(imageBytes(900, 500)), 'b.jpg', ['alt_text' => 'x'], null)->asset;
    Bus::fake([]);   // renditions were queued before fake; make them now
    app(VariantGenerator::class)->generate($asset);
    expect(count(Storage::disk('media_local_public')->allFiles()))->toBeGreaterThan(2);

    expect(fn () => $this->trash->purge($asset))->toThrow(LogicException::class);

    $this->trash->trash($asset);
    $this->trash->purge($asset->fresh());

    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull()
        ->and(DB::table('media_variants')->where('media_id', $asset->id)->count())->toBe(0);
    expect(array_values(array_filter(Storage::disk('media_local_public')->allFiles(), fn ($f) => str_contains($f, pathinfo($asset->path, PATHINFO_FILENAME)))))->toBe([]);
});

test('a reference added after the file was trashed stops the permanent delete', function () {
    $this->trash->trash($this->asset);
    $record = MediaAsset::withTrashed()->find($this->asset->id);
    insertPost(['cover_media_id' => $this->asset->id]);   // somebody picked it while it sat in the trash

    expect(fn () => $this->trash->purge($record))->toThrow(MediaInUse::class);
    expect(Storage::disk('media_local_public')->exists($this->asset->path))->toBeTrue();
});

test('trash, restore and delete are in the audit trail', function () {
    $this->trash->trash($this->asset);
    $this->trash->restore(MediaAsset::withTrashed()->find($this->asset->id));

    $actions = DB::table('audit_log')->where('subject_table', 'media_assets')->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['create', 'delete', 'restore']);
});

test('the library search finds by name, alt text, caption and credit, ignoring case', function () {
    $this->asset->update(['alt_text' => 'سوق نابلس القديم', 'caption' => 'Old Souk', 'credit' => 'Photographer Ali']);
    $other = app(MediaUploader::class)->store(uploadFile(imageBytes(111, 99)), 'second.jpg', ['alt_text' => 'شيء آخر'], null)->asset;

    expect(MediaAsset::search('سوق')->pluck('id')->all())->toBe([$this->asset->id])
        ->and(MediaAsset::search('old souk')->pluck('id')->all())->toBe([$this->asset->id])
        ->and(MediaAsset::search('ALI')->pluck('id')->all())->toBe([$this->asset->id])
        ->and(MediaAsset::search('second')->pluck('id')->all())->toBe([$other->id])
        ->and(MediaAsset::search('')->count())->toBe(2)
        ->and(MediaAsset::search('100%')->count())->toBe(0);   // % is a character, not a wildcard
});
