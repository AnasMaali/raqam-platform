<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/05-video-awareness.sql, ported from database/tests/schema_tests.sql.

function insertYoutubeVideo(string $youtubeId): int
{
    $video = DB::table('videos')->insertGetId(['source' => 'youtube']);
    DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => $youtubeId, 'source_title' => 'حلقة']);

    return $video;
}

function insertUploadedVideo(): int
{
    return DB::table('videos')->insertGetId([
        'source' => 'upload', 'display_title' => 'مقطع',
        'upload_media_id' => insertMedia(['mime_type' => 'video/mp4', 'alt_text' => null, 'duration_seconds' => 30, 'width' => 1080, 'height' => 1920]),
    ]);
}

test('an uploaded video needs its file', function () {
    expect(rejected(fn () => DB::table('videos')->insert(['source' => 'upload', 'display_title' => 'مقطع'])))
        ->toThrow(QueryException::class, '"videos_check"');
});

test('a YouTube video has no uploaded file', function () {
    expect(rejected(fn () => DB::table('videos')->insert(['source' => 'youtube', 'upload_media_id' => insertMedia()])))
        ->toThrow(QueryException::class, '"videos_check"');
});

test('an uploaded video needs a display title', function () {
    expect(rejected(fn () => DB::table('videos')->insert([
        'source' => 'upload', 'upload_media_id' => insertMedia(['mime_type' => 'video/mp4', 'alt_text' => null, 'duration_seconds' => 30, 'width' => 1080, 'height' => 1920]),
    ])))->toThrow(QueryException::class, '"videos_check1"');
});

test('a YouTube id has 11 URL-safe characters', function () {
    $video = DB::table('videos')->insertGetId(['source' => 'youtube']);

    expect(rejected(fn () => DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => 'bad id!', 'source_title' => 'x'])))
        ->toThrow(QueryException::class, '"youtube_videos_youtube_id_check"');
});

test('YouTube details cannot hang off an uploaded video (subtype integrity)', function () {
    $upload = insertUploadedVideo();

    expect(rejected(fn () => DB::table('youtube_videos')->insert(['video_id' => $upload, 'youtube_id' => 'dQw4w9WgXcQ', 'source_title' => 'x'])))
        ->toThrow(QueryException::class, '"youtube_videos_video_id_source_fkey"');
});

test('playlist positions can be swapped inside one transaction (deferrable unique)', function () {
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'الموسم الأول', 'slug' => 'season-1']);
    $first = insertYoutubeVideo('dQw4w9WgXcQ');
    $second = insertYoutubeVideo('abcdefghijk');
    DB::table('playlist_videos')->insert([
        ['playlist_id' => $playlist, 'video_id' => $first, 'sort_order' => 1],
        ['playlist_id' => $playlist, 'video_id' => $second, 'sort_order' => 2],
    ]);

    // Drag-and-drop sends one UPDATE per row; in between, two videos share position 2.
    DB::table('playlist_videos')->where('video_id', $first)->update(['sort_order' => 2]);
    DB::table('playlist_videos')->where('video_id', $second)->update(['sort_order' => 1]);
    DB::statement('SET CONSTRAINTS playlist_videos_position_uq IMMEDIATE');   // what COMMIT would check

    expect(DB::table('playlist_videos')->orderBy('sort_order')->pluck('video_id')->all())->toBe([$second, $first]);
});

test('two videos cannot keep the same playlist position', function () {
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'الموسم الأول', 'slug' => 'season-1']);
    DB::table('playlist_videos')->insert([
        ['playlist_id' => $playlist, 'video_id' => insertYoutubeVideo('dQw4w9WgXcQ'), 'sort_order' => 1],
        ['playlist_id' => $playlist, 'video_id' => insertYoutubeVideo('abcdefghijk'), 'sort_order' => 2],
    ]);

    expect(rejected(function () {
        DB::statement('SET CONSTRAINTS playlist_videos_position_uq IMMEDIATE');
        DB::table('playlist_videos')->update(['sort_order' => 1]);
    }))->toThrow(QueryException::class, '"playlist_videos_position_uq"');
});

test('a character has each pose once', function () {
    ensure('pose_types', ['code' => 'explaining', 'name_ar' => 'تشرح', 'sort_order' => 1]);
    $character = DB::table('characters')->insertGetId(['name_ar' => 'رَقَم', 'slug' => 'raqam', 'role_ar' => 'الراوي', 'accent_color' => '#0B3D5C']);
    $pose = ['character_id' => $character, 'pose_type_code' => 'explaining', 'static_media_id' => insertMedia(['mime_type' => 'image/png'])];
    DB::table('character_poses')->insert($pose);

    expect(rejected(fn () => DB::table('character_poses')->insert($pose)))
        ->toThrow(QueryException::class, '"character_poses_character_id_pose_type_code_key"');
});

test('a pose image in use cannot be deleted', function () {
    ensure('pose_types', ['code' => 'explaining', 'name_ar' => 'تشرح', 'sort_order' => 1]);
    $character = DB::table('characters')->insertGetId(['name_ar' => 'رَقَم', 'slug' => 'raqam', 'role_ar' => 'الراوي', 'accent_color' => '#0B3D5C']);
    $media = insertMedia(['mime_type' => 'image/png']);
    DB::table('character_poses')->insert(['character_id' => $character, 'pose_type_code' => 'explaining', 'static_media_id' => $media]);

    expect(rejected(fn () => DB::table('media_assets')->delete($media)))
        ->toThrow(QueryException::class, '"character_poses_static_media_id_fkey"');
});
