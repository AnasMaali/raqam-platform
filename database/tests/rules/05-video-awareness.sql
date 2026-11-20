-- =====================================================================
--  Raqam schema — rule tests for 05-video-awareness.sql
--  Breaks the CHECKs of videos, youtube_videos, video_chapters,
--  pose_types and awareness_types that schema_tests.sql does not reach.
--  Runs in the same session, after schema_tests.sql; every value this
--  file inserts carries "r05".
-- =====================================================================

-- ---------------------------------------------------------------- videos
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, duration_seconds, width, height)
VALUES ('r05/clip.mp4', 'r05-clip.mp4', 'video/mp4', 1000, repeat('5', 64), 30, 1920, 1080);

SELECT pg_temp.expect_fail('videos: source other than youtube or upload',
  $$INSERT INTO videos (source, display_title) VALUES ('vimeo', 'r05 مصدر آخر')$$, 'videos_source_check');
SELECT pg_temp.expect_fail('videos: uploaded clip without a display title',
  $$INSERT INTO videos (source, upload_media_id) SELECT 'upload', id FROM media_assets WHERE path = 'r05/clip.mp4'$$, 'videos_check1');
SELECT pg_temp.expect_fail('videos: duration of zero seconds',
  $$INSERT INTO videos (source, display_title, duration_seconds) VALUES ('youtube', 'r05 مدة صفر', 0)$$, 'videos_duration_seconds_check');

-- The upload with a title is accepted; a YouTube video needs no title of its own.
INSERT INTO videos (source, display_title, upload_media_id, duration_seconds)
SELECT 'upload', 'r05 مقطع', id, 30 FROM media_assets WHERE path = 'r05/clip.mp4';
INSERT INTO videos (source, display_title, duration_seconds) VALUES ('youtube', 'r05 فيديو', 300);

-- ---------------------------------------------------------------- youtube videos
SELECT pg_temp.expect_fail('youtube_videos: availability outside ok/private/removed/unknown',
  $$INSERT INTO youtube_videos (video_id, youtube_id, source_title, availability)
    SELECT id, 'r05aaaaaaaa', 'r05 title', 'gone' FROM videos WHERE display_title = 'r05 فيديو'$$, 'youtube_videos_availability_check');
SELECT pg_temp.expect_fail('youtube_videos: subtype row with a source other than youtube',
  $$INSERT INTO youtube_videos (video_id, source, youtube_id, source_title)
    SELECT id, 'upload', 'r05bbbbbbbb', 'r05 title' FROM videos WHERE source = 'upload' ORDER BY id LIMIT 1$$, 'youtube_videos_source_check');
INSERT INTO youtube_videos (video_id, youtube_id, source_title, availability)
SELECT id, 'r05aaaaaaaa', 'r05 title', 'private' FROM videos WHERE display_title = 'r05 فيديو';

-- ---------------------------------------------------------------- video chapters
INSERT INTO video_chapters (video_id, starts_at_seconds, title)
SELECT id, 0, 'r05 البداية' FROM videos WHERE display_title = 'r05 فيديو';
SELECT pg_temp.expect_fail('video_chapters: chapter starting before second zero',
  $$INSERT INTO video_chapters (video_id, starts_at_seconds, title)
    SELECT id, -5, 'r05 قبل البداية' FROM videos WHERE display_title = 'r05 فيديو'$$, 'video_chapters_starts_at_seconds_check');

-- ---------------------------------------------------------------- pose and awareness types
SELECT pg_temp.expect_fail('pose_types: code must be lower-case latin letters only',
  $$INSERT INTO pose_types (code, name_ar) VALUES ('wave_r', 'r05 يلوّح')$$, 'pose_types_code_check');
SELECT pg_temp.expect_fail('awareness_types: code must be lower-case latin letters and underscores only',
  $$INSERT INTO awareness_types (code, name_ar) VALUES ('Tips', 'r05 نصائح')$$, 'awareness_types_code_check');
