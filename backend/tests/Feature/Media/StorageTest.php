<?php

use App\Media\MediaStorage;
use App\Media\MediaUploader;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** Laravel answers 403 to a bad signature outside production and 404 in production; either way the file is not served. */
function refusedStatus(): int
{
    return app()->isProduction() ? 404 : 403;
}

function put(string $visibility = 'public', ?string $bytes = null): MediaAsset
{
    return app(MediaUploader::class)->store(uploadFile($bytes ?? imageBytes(rand(60, 90), 60)), 'a.jpg', ['alt_text' => 'x', 'visibility' => $visibility], null)->asset;
}

// ---- local or R2 by configuration only (9.10) -----------------------------------------------------

test('the same code writes to this server or to R2, chosen only by configuration', function () {
    Bus::fake();
    Storage::fake('media_local_public');
    Storage::fake('media_r2_public');

    config(['media.disk' => 'local']);
    $local = put();
    config(['media.disk' => 'r2']);
    $remote = put();

    expect($local->disk)->toBe('local')->and($remote->disk)->toBe('r2')
        ->and(Storage::disk('media_local_public')->exists($local->path))->toBeTrue()
        ->and(Storage::disk('media_local_public')->exists($remote->path))->toBeFalse()
        ->and(Storage::disk('media_r2_public')->exists($remote->path))->toBeTrue();
});

test('each file keeps being read from the disk it was stored on after the setting changes', function () {
    Bus::fake();
    Storage::fake('media_local_public');
    Storage::fake('media_r2_public');
    config(['media.disk' => 'local']);
    $asset = put();

    config(['media.disk' => 'r2']);

    expect(app(MediaStorage::class)->read($asset))->not->toBeEmpty();
});

test('renditions follow their file to the same disk', function () {
    Storage::fake('media_r2_public');
    config(['media.disk' => 'r2']);

    $asset = put();

    expect(Storage::disk('media_r2_public')->allFiles())->toContain($asset->path)->and(count(Storage::disk('media_r2_public')->allFiles()))->toBeGreaterThan(1);
});

test('an unknown disk name is refused instead of silently storing nowhere', function () {
    config(['media.disk' => 'floppy']);

    expect(fn () => app(MediaStorage::class)->activeDisk())->toThrow(InvalidArgumentException::class);
});

test('R2 is configured from the environment only, with nothing secret in the repository', function () {
    foreach (['media_r2_public', 'media_r2_private'] as $disk) {
        $config = config("filesystems.disks.{$disk}");
        expect($config['driver'])->toBe('s3')->and($config['endpoint'])->toBeEmpty()->and($config['key'])->toBeEmpty()->and($config['secret'])->toBeEmpty();   // empty in the repository and in CI: real values only in .env
    }
    expect(config('filesystems.disks.media_r2_private.url'))->toBeEmpty();
});

// ---- public and private ---------------------------------------------------------------------------

test('a public file has a plain address under the media directory', function () {
    Bus::fake();
    Storage::fake('media_local_public');
    $asset = put();

    expect(app(MediaStorage::class)->url($asset))->toContain($asset->path);
});

test('a private file never has a plain address', function () {
    Bus::fake();
    Storage::fake('media_local_private');
    $asset = put('private');

    expect(fn () => Storage::disk('media_local_private')->url($asset->path))->not->toThrow(Throwable::class);   // the disk may build one...
    expect(config('filesystems.disks.media_local_private.visibility'))->toBe('private')
        ->and(config('filesystems.disks.media_local_private.root'))->not->toContain('public');
});

describe('private files over HTTP', function () {
    beforeEach(function () {
        Bus::fake();
        $this->root = sys_get_temp_dir().'/raqam-private-'.bin2hex(random_bytes(4));
        config(['filesystems.disks.media_local_private.root' => $this->root]);
        Storage::forgetDisk('media_local_private');
        $this->asset = put('private');
    });

    afterEach(fn () => File::deleteDirectory($this->root));

    test('the file is stored outside the web server\'s public directory', function () {
        expect(file_exists($this->root.'/'.$this->asset->path))->toBeTrue()
            ->and(realpath($this->root))->not->toStartWith(realpath(public_path()))
            ->and(realpath($this->root))->not->toStartWith(realpath(storage_path('app/public')));
    });

    test('a signed, unexpired link serves it, sandboxed and never cached', function () {
        $url = app(MediaStorage::class)->url($this->asset);

        $response = $this->get($url)->assertOk();
        expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    test('the same address without its signature is refused', function () {
        $path = '/private-media/'.$this->asset->path;

        $this->get($path)->assertStatus(refusedStatus());
        $this->get($path.'?signature=abc&expires=9999999999')->assertStatus(refusedStatus());
    });

    test('a link whose path or expiry was changed is refused', function () {
        $url = app(MediaStorage::class)->url($this->asset);
        $other = put('private', imageBytes(77, 55));

        $this->get(str_replace($this->asset->path, $other->path, $url))->assertStatus(refusedStatus());
        $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->assertStatus(refusedStatus());
    });

    test('an expired link is refused', function () {
        $url = app(MediaStorage::class)->url($this->asset);

        $this->travel(11)->minutes();

        $this->get($url)->assertStatus(refusedStatus());
    });

    test('nothing can be uploaded through the file route', function () {
        $this->put('/private-media/'.$this->asset->path, ['x' => 'y'])->assertStatus(refusedStatus());
        $this->put('/private-media/new.jpg')->assertStatus(refusedStatus());
    });

    test('a path that climbs out of the folder is refused', function () {
        $url = app(MediaStorage::class)->url($this->asset);
        $signature = strstr($url, '?');

        $this->get('/private-media/../../../../.env'.$signature)->assertStatus(refusedStatus());
        $this->get('/private-media/%2e%2e/%2e%2e/.env'.$signature)->assertStatus(refusedStatus());
    });

    test('private files are not reachable from the public media address', function () {
        $this->get('/storage/media/'.$this->asset->path)->assertNotFound();
    });
});

test('the generic local disk no longer serves files at /storage', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
    $this->get('/storage/anything.txt')->assertNotFound();
});
