<?php

use App\Filament\Resources\Playlists\Pages\CreatePlaylist;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Videos\Pages\CreateVideo;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Filament\Resources\Videos\VideoResource;
use App\Jobs\GenerateMediaVariants;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Video\VideoLibrary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    Bus::fake([GenerateMediaVariants::class]);
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    config(['raqam.youtube.key' => null]);
});

function panelVideo(string $id = 'dQw4w9WgXcQ', string $title = 'حلقة', ?int $seconds = 600): Video
{
    $video = app(VideoLibrary::class)->addYoutube("https://youtu.be/$id", ['title' => $title]);
    $video->forceFill(['duration_seconds' => $seconds])->save();

    return $video;
}

test('adding a video by link without a key opens the hand-entry fields, then saves (8.2, 8.3)', function () {
    $page = Livewire::test(CreateVideo::class)
        ->fillForm(['source' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->call('create')
        ->assertHasFormErrors(['url']);

    expect($page->get('data.manual'))->toBeTrue();

    $page->fillForm(['manual_title' => 'حلقة الاقتصاد', 'manual_duration' => '12:30'])->call('create')->assertHasNoFormErrors();

    $video = Video::sole();
    expect($video->youtube->youtube_id)->toBe('dQw4w9WgXcQ')->and($video->title())->toBe('حلقة الاقتصاد')->and($video->duration_seconds)->toBe(750)
        ->and($video->created_by)->toBe($this->admin->id);
});

test('with a key the link is enough', function () {
    config(['raqam.youtube.key' => 'test-key-1234567890']);
    Http::swap(new Factory);
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [['id' => 'dQw4w9WgXcQ', 'snippet' => ['title' => 'من يوتيوب'], 'contentDetails' => ['duration' => 'PT5M'], 'status' => ['privacyStatus' => 'public']]]])]);

    Livewire::test(CreateVideo::class)->fillForm(['source' => 'youtube', 'url' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ'])->call('create')->assertHasNoFormErrors();

    expect(Video::sole()->title())->toBe('من يوتيوب')->and(Video::sole()->youtube->is_short)->toBeTrue();
});

test('a link that is not YouTube is refused under the field', function () {
    Livewire::test(CreateVideo::class)->fillForm(['source' => 'youtube', 'url' => 'https://example.com/watch?v=dQw4w9WgXcQ'])->call('create')->assertHasFormErrors(['url']);

    expect(Video::count())->toBe(0);
});

test('a short MP4 is uploaded with a title (8.11)', function () {
    fakeFfprobe(ffprobeAnswer(45, 1080, 1920));

    Livewire::test(CreateVideo::class)
        ->fillForm(['source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('clip.mp4', mp4Bytes()), 'upload_title' => 'لقطة من السوق'])
        ->call('create')->assertHasNoFormErrors();

    $video = Video::sole();
    expect($video->source)->toBe('upload')->and($video->title())->toBe('لقطة من السوق')->and($video->duration_seconds)->toBe(45)
        ->and($video->upload->mime_type)->toBe('video/mp4');
});

test('a clip that is too long is refused with the reason under the file', function () {
    fakeFfprobe(ffprobeAnswer(120));

    Livewire::test(CreateVideo::class)
        ->fillForm(['source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('clip.mp4', mp4Bytes()), 'upload_title' => 'طويل'])
        ->call('create')->assertHasFormErrors(['file']);

    expect(Video::count())->toBe(0);
});

test('a script named .mp4 never gets in', function () {
    fakeFfprobe(ffprobeAnswer());

    Livewire::test(CreateVideo::class)
        ->fillForm(['source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('clip.mp4', "<?php system('id');"), 'upload_title' => 'x'])
        ->call('create')->assertHasFormErrors();

    expect(Video::count())->toBe(0)->and(Storage::disk('media_local_public')->allFiles())->toBe([]);
});

test('editing changes the shown title and description, not YouTube text, and keeps chapters (8.4, 8.8)', function () {
    $video = panelVideo();

    Livewire::test(EditVideo::class, ['record' => $video->id])
        ->fillForm(['display_title' => 'عنوان الموقع', 'display_description' => 'وصف الموقع', 'chapters' => [['time' => '1:30', 'title' => 'الفقرة الأولى'], ['time' => '0:00', 'title' => 'البداية']]])
        ->call('save')->assertHasNoFormErrors();

    $video->refresh();
    expect($video->title())->toBe('عنوان الموقع')->and($video->youtube->source_title)->toBe('حلقة')
        ->and(app(VideoLibrary::class)->chapters($video))->toBe([['time' => '0:00', 'title' => 'البداية'], ['time' => '1:30', 'title' => 'الفقرة الأولى']]);

    expect(array_values(Livewire::test(EditVideo::class, ['record' => $video->id])->get('data.chapters')))->toBe([['time' => '0:00', 'title' => 'البداية'], ['time' => '1:30', 'title' => 'الفقرة الأولى']]);
});

test('a chapter past the end of the video is refused on the screen and nothing is saved (8.8)', function () {
    $video = panelVideo(seconds: 600);

    Livewire::test(EditVideo::class, ['record' => $video->id])
        ->fillForm(['display_title' => 'لن يُحفظ', 'chapters' => [['time' => '10:00', 'title' => 'عند النهاية']]])
        ->call('save')->assertHasFormErrors(['chapters']);

    expect($video->refresh()->display_title)->toBeNull()->and(DB::table('video_chapters')->count())->toBe(0);
});

test('the list searches by title, filters by source, playlist and section (8.10)', function () {
    $a = panelVideo('aaaaaaaaaaa', 'الاقتصاد الفلسطيني');
    $b = panelVideo('bbbbbbbbbbb', 'الطقس');
    $upload = insertUploadedVideo();
    $podcast = DB::table('sections')->where('key', 'podcast')->value('id');
    $list = DB::table('playlists')->insertGetId(['name_ar' => 'الموسم', 'slug' => 's', 'section_id' => $podcast]);
    DB::table('playlist_videos')->insert(['playlist_id' => $list, 'video_id' => $a->id, 'sort_order' => 1]);

    Livewire::test(ListVideos::class)
        ->assertCanSeeTableRecords([$a, $b, Video::find($upload)])
        ->searchTable('الاقتصاد')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->searchTable('')
        ->filterTable('source', 'upload')->assertCanSeeTableRecords([Video::find($upload)])->assertCanNotSeeTableRecords([$a, $b])
        ->removeTableFilter('source')
        ->filterTable('playlist', $list)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->removeTableFilter('playlist')
        ->filterTable('section', $podcast)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
});

test('a bad filter value never reaches the query', function () {
    panelVideo();

    Livewire::test(ListVideos::class)->filterTable('playlist', '1; drop table videos')->assertSuccessful();
    expect(DB::table('videos')->count())->toBe(1);
});

test('the usage list shows the items and playlists that use a video', function () {
    ensurePostKinds();
    $video = panelVideo();
    insertPost(['title' => 'مادة تستعمله', 'primary_video_id' => $video->id]);
    $list = DB::table('playlists')->insertGetId(['name_ar' => 'قائمة الاختبار', 'slug' => 'test-list']);
    DB::table('playlist_videos')->insert(['playlist_id' => $list, 'video_id' => $video->id, 'sort_order' => 1]);

    expect(VideoResource::usage($video))->toContain('مادة تستعمله — فيديو رئيسي')->toContain('قائمة التشغيل: قائمة الاختبار');
});

test('a playlist is created with its videos in the order given, and re-ordering saves the new order (8.9)', function () {
    $a = panelVideo('aaaaaaaaaaa', 'أ');
    $b = panelVideo('bbbbbbbbbbb', 'ب');
    $c = panelVideo('ccccccccccc', 'ج');

    Livewire::test(CreatePlaylist::class)
        ->fillForm(['name_ar' => 'الموسم الأول', 'videos' => [['video_id' => $c->id], ['video_id' => $a->id], ['video_id' => $b->id]]])
        ->call('create')->assertHasNoFormErrors();

    $playlist = Playlist::sole();
    expect($playlist->slug)->not->toBe('')->and(app(VideoLibrary::class)->playlistVideoIds($playlist->id))->toBe([$c->id, $a->id, $b->id]);

    $edit = Livewire::test(EditPlaylist::class, ['record' => $playlist->id]);
    expect(collect($edit->get('data.videos'))->pluck('video_id')->map(fn ($id) => (int) $id)->all())->toBe([$c->id, $a->id, $b->id]);
    $edit
        ->fillForm(['videos' => [['video_id' => $b->id], ['video_id' => $c->id]]])
        ->call('save')->assertHasNoFormErrors();

    expect(app(VideoLibrary::class)->playlistVideoIds($playlist->id))->toBe([$b->id, $c->id]);
});

test('a playlist can be a podcast season, and a season belongs to one playlist only (5.1)', function () {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);

    Livewire::test(CreatePlaylist::class)->fillForm(['name_ar' => 'الموسم 1', 'season_id' => $season])->call('create')->assertHasNoFormErrors();
    expect(DB::table('podcast_seasons')->where('id', $season)->value('playlist_id'))->toBe(Playlist::sole()->id);

    Livewire::test(CreatePlaylist::class)->fillForm(['name_ar' => 'نسخة ثانية', 'season_id' => $season])->call('create')->assertHasFormErrors(['season_id']);
    expect(Playlist::count())->toBe(1);
});

test('a playlist takes a video from the library only', function () {
    Livewire::test(CreatePlaylist::class)->fillForm(['name_ar' => 'قائمة', 'videos' => [['video_id' => 987654]]])->call('create')->assertHasFormErrors();

    expect(Playlist::count())->toBe(0);
});
