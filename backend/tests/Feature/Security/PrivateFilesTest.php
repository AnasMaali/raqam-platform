<?php

use App\Media\MediaUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
});

test('a private file is not reachable by any address without a signature', function () {
    $asset = app(MediaUploader::class)->store(uploadFile(imageBytes(80, 80)), 'secret.jpg', ['alt_text' => 'x', 'visibility' => 'private'], null)->asset;

    foreach (["/private-media/{$asset->path}", "/storage/{$asset->path}", "/storage/media/{$asset->path}", "/private-media/../{$asset->path}", '/private-media/%2e%2e/%2e%2e/.env'] as $url) {
        expect($this->get($url)->status())->toBeIn([403, 404], $url);
    }
});

test('nobody can write to the private disk through its upload address without a signature', function () {
    $this->call('PUT', '/private-media/planted.php', [], [], [], [], '<?php echo 1;')->assertStatus(app()->isProduction() ? 404 : 403);

    expect(Storage::disk('media_local_private')->exists('planted.php'))->toBeFalse();
});

test('the local disk is not served at /storage, so files in storage/app are not downloadable', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
    $this->get('/storage/.gitignore')->assertNotFound();
    $this->get('/storage/framework/sessions')->assertNotFound();
});
