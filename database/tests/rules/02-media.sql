-- =====================================================================
--  Raqam schema — rule tests for 02-media.sql
--  media_assets (allowed values, dimensions, focal point, size limits)
--  and media_variants. Runs after schema_tests.sql in the same session;
--  unique values carry the prefix r02.
-- =====================================================================

-- ---------------------------------------------------------------- media_assets: allowed values
SELECT pg_temp.expect_fail('media_assets: an unknown visibility is refused',
  $$INSERT INTO media_assets (visibility, path, original_name, mime_type, size_bytes, checksum_sha256)
    VALUES ('secret', 'r02/vis.pdf', 'vis.pdf', 'application/pdf', 1000, encode(sha256('r02-vis'), 'hex'))$$, 'media_assets_visibility_check');
SELECT pg_temp.expect_fail('media_assets: an unknown storage disk is refused',
  $$INSERT INTO media_assets (disk, path, original_name, mime_type, size_bytes, checksum_sha256)
    VALUES ('s3', 'r02/disk.pdf', 'disk.pdf', 'application/pdf', 1000, encode(sha256('r02-disk'), 'hex'))$$, 'media_assets_disk_check');
SELECT pg_temp.expect_fail('media_assets: a file type outside the allow-list (GIF) is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
    VALUES ('r02/anim.gif', 'anim.gif', 'image/gif', 1000, encode(sha256('r02-gif'), 'hex'), 'alt', 100, 100)$$, 'media_assets_mime_type_check');
SELECT pg_temp.expect_fail('media_assets: an empty file is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256)
    VALUES ('r02/empty.pdf', 'empty.pdf', 'application/pdf', 0, encode(sha256('r02-empty'), 'hex'))$$, 'media_assets_size_bytes_check');
SELECT pg_temp.expect_fail('media_assets: a zero duration is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, duration_seconds, width, height)
    VALUES ('r02/zero.mp4', 'zero.mp4', 'video/mp4', 1000, encode(sha256('r02-zero'), 'hex'), 0, 1920, 1080)$$, 'media_assets_duration_seconds_check');

-- ---------------------------------------------------------------- media_assets: dimensions
SELECT pg_temp.expect_fail('media_assets: a zero width is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
    VALUES ('r02/w0.png', 'w0.png', 'image/png', 1000, encode(sha256('r02-w0'), 'hex'), 'alt', 0, 100)$$, 'media_assets_width_check');
SELECT pg_temp.expect_fail('media_assets: a zero height is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
    VALUES ('r02/h0.png', 'h0.png', 'image/png', 1000, encode(sha256('r02-h0'), 'hex'), 'alt', 100, 0)$$, 'media_assets_height_check');

-- ---------------------------------------------------------------- media_assets: focal point
SELECT pg_temp.expect_fail('media_assets: focal x without focal y is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height, focal_x)
    VALUES ('r02/fx.png', 'fx.png', 'image/png', 1000, encode(sha256('r02-fx'), 'hex'), 'alt', 100, 100, 0.5)$$, 'media_assets_check');
SELECT pg_temp.expect_fail('media_assets: focal x above 1 is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height, focal_x, focal_y)
    VALUES ('r02/fx1.png', 'fx1.png', 'image/png', 1000, encode(sha256('r02-fx1'), 'hex'), 'alt', 100, 100, 1.5, 0.5)$$, 'media_assets_focal_x_check');
SELECT pg_temp.expect_fail('media_assets: a negative focal y is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height, focal_x, focal_y)
    VALUES ('r02/fy.png', 'fy.png', 'image/png', 1000, encode(sha256('r02-fy'), 'hex'), 'alt', 100, 100, 0.5, -0.1)$$, 'media_assets_focal_y_check');

-- ---------------------------------------------------------------- media_assets: alt text and size limits
INSERT INTO media_assets (visibility, path, original_name, mime_type, size_bytes, checksum_sha256, width, height)
VALUES ('private', 'r02/private.jpg', 'private.jpg', 'image/jpeg', 1000, encode(sha256('r02-private'), 'hex'), 100, 100);
SELECT pg_temp.expect_eq('media_assets: a private image needs no alt text',
  (SELECT count(*)::text FROM media_assets WHERE path = 'r02/private.jpg'), '1');
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256)
VALUES ('r02/limit.pdf', 'limit.pdf', 'application/pdf', 20 * 1024 * 1024, encode(sha256('r02-limit-pdf'), 'hex'));
SELECT pg_temp.expect_fail('media_assets: a PDF over 20 MB is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256)
    VALUES ('r02/big.pdf', 'big.pdf', 'application/pdf', 20 * 1024 * 1024 + 1, encode(sha256('r02-big-pdf'), 'hex'))$$, 'media_assets_check5');
SELECT pg_temp.expect_fail('media_assets: an uploaded clip over 30 MB is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, duration_seconds, width, height)
    VALUES ('r02/big.mp4', 'big.mp4', 'video/mp4', 30 * 1024 * 1024 + 1, encode(sha256('r02-big-mp4'), 'hex'), 30, 1920, 1080)$$, 'media_assets_check6');
SELECT pg_temp.expect_fail('media_assets: an uploaded clip without a duration is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, width, height)
    VALUES ('r02/nodur.mp4', 'nodur.mp4', 'video/mp4', 1000, encode(sha256('r02-nodur'), 'hex'), 1920, 1080)$$, 'media_assets_check6');

-- ---------------------------------------------------------------- media_variants
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
VALUES ('r02/base.png', 'base.png', 'image/png', 5000, encode(sha256('r02-base'), 'hex'), 'صورة', 1600, 900);
INSERT INTO media_variants (media_id, variant, format, width, height, path, size_bytes)
SELECT id, 'md', 'webp', 800, 450, 'r02/base-md.webp', 2000 FROM media_assets WHERE path = 'r02/base.png';
SELECT pg_temp.expect_fail('media_variants: an unknown size name is refused',
  $$INSERT INTO media_variants (media_id, variant, format, width, height, path, size_bytes)
    SELECT id, 'huge', 'webp', 800, 450, 'r02/base-huge.webp', 2000 FROM media_assets WHERE path = 'r02/base.png'$$, 'media_variants_variant_check');
SELECT pg_temp.expect_fail('media_variants: an unknown format is refused',
  $$INSERT INTO media_variants (media_id, variant, format, width, height, path, size_bytes)
    SELECT id, 'md', 'gif', 800, 450, 'r02/base-md.gif', 2000 FROM media_assets WHERE path = 'r02/base.png'$$, 'media_variants_format_check');
SELECT pg_temp.expect_fail('media_variants: a zero width is refused',
  $$INSERT INTO media_variants (media_id, variant, format, width, height, path, size_bytes)
    SELECT id, 'sm', 'webp', 0, 450, 'r02/base-sm.webp', 2000 FROM media_assets WHERE path = 'r02/base.png'$$, 'media_variants_width_check');
SELECT pg_temp.expect_fail('media_variants: a zero height is refused',
  $$INSERT INTO media_variants (media_id, variant, format, width, height, path, size_bytes)
    SELECT id, 'sm', 'webp', 400, 0, 'r02/base-sm.webp', 2000 FROM media_assets WHERE path = 'r02/base.png'$$, 'media_variants_height_check');
SELECT pg_temp.expect_fail('media_variants: an empty file is refused',
  $$INSERT INTO media_variants (media_id, variant, format, width, height, path, size_bytes)
    SELECT id, 'sm', 'webp', 400, 225, 'r02/base-sm.webp', 0 FROM media_assets WHERE path = 'r02/base.png'$$, 'media_variants_size_bytes_check');
