<?php

// The rows the query-growth tests make (ApiQueryGrowthTest, PanelQueryGrowthTest): each call adds one more item of a list.

use Illuminate\Support\Facades\DB;

/** A YouTube video with a poster of ours and two chapters, in the playlist "قائمة". */
function growthVideo(int $i): int
{
    static $playlist = null;
    $playlist = DB::table('playlists')->where('slug', 'قائمة')->value('id') ?? DB::table('playlists')->insertGetId(['name_ar' => 'قائمة', 'slug' => 'قائمة']);
    $video = DB::table('videos')->insertGetId(['source' => 'youtube', 'duration_seconds' => 600, 'poster_media_id' => insertMedia()]);
    DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => sprintf('vid%08d', $i), 'source_title' => "فيديو {$i}"]);
    DB::table('video_chapters')->insert([['video_id' => $video, 'starts_at_seconds' => 0, 'title' => 'البداية'], ['video_id' => $video, 'starts_at_seconds' => 60, 'title' => 'المحور']]);
    DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'sort_order' => $i + 1]);

    return $video;
}

/** A company in a sector and a city, with a logo. */
function growthOrganization(int $i): int
{
    $sector = DB::table('sectors')->insertGetId(['name_ar' => "قطاع {$i}", 'slug' => "قطاع-{$i}"]);

    return DB::table('organizations')->insertGetId(['name' => "شركة {$i}", 'slug' => "شركة-{$i}", 'sector_id' => $sector,
        'city_id' => DB::table('cities')->value('id'), 'logo_media_id' => insertMedia()]);
}

/** A guest with a photo, a company and two links. */
function growthGuest(int $i): int
{
    $guest = DB::table('guests')->insertGetId(['name' => "ضيف {$i}", 'slug' => "ضيف-{$i}", 'photo_media_id' => insertMedia(), 'organization_id' => growthOrganization($i)]);
    DB::table('guest_links')->insert([['guest_id' => $guest, 'platform_code' => 'facebook', 'url' => "https://facebook.com/g{$i}"], ['guest_id' => $guest, 'platform_code' => 'x', 'url' => "https://x.com/g{$i}"]]);

    return $guest;
}

/** A writer on the team page, with a photo and two links. */
function growthAuthor(int $i): void
{
    $author = DB::table('authors')->insertGetId(['name' => "كاتب {$i}", 'slug' => "كاتب-{$i}", 'photo_media_id' => insertMedia(), 'is_active' => true, 'team_sort_order' => $i + 1]);
    DB::table('author_links')->insert([['author_id' => $author, 'platform_code' => 'facebook', 'url' => "https://facebook.com/a{$i}"], ['author_id' => $author, 'platform_code' => 'x', 'url' => "https://x.com/a{$i}"]]);
}

/** A published item of $kind, with what its kind needs before it leaves draft added by $subtype. */
function growthPost(string $kind, ?Closure $subtype = null): int
{
    ensurePostKinds();
    $post = insertPost(['kind_code' => $kind, 'status' => 'published', 'published_at' => now()->subHour(), 'body' => '<p>نص</p>', 'cover_media_id' => insertMedia(),
        'category_id' => in_array($kind, ['news', 'report', 'opinion', 'infographic'], true) ? insertCategory('تصنيف-'.uniqid()) : null]);
    $subtype && $subtype($post);

    return $post;
}

/** A character with two poses and a published card. */
function growthCharacter(int $i): int
{
    $character = DB::table('characters')->insertGetId(['name_ar' => "شخصية {$i}", 'slug' => "شخصية-{$i}", 'role_ar' => 'دور', 'accent_color' => '#124FFF', 'sort_order' => $i + 1, 'is_active' => true]);
    $poses = [];
    foreach (['explaining', 'smiling'] as $pose) {
        $poses[] = DB::table('character_poses')->insertGetId(['character_id' => $character, 'pose_type_code' => $pose, 'static_media_id' => insertMedia(['mime_type' => 'image/png'])]);
    }
    growthCard($poses[0]);

    return $character;
}

/** A published awareness card said by a pose. */
function growthCard(int $pose): int
{
    return growthPost('awareness', fn (int $post) => DB::table('awareness_cards')->insert(['post_id' => $post, 'kind_code' => 'awareness', 'character_pose_id' => $pose, 'awareness_type_code' => 'advice']));
}
