-- =====================================================================
--  Raqam schema — rule tests for 06-content.sql
--  Breaks the CHECKs of post_kinds, posts, post_related, post_revisions,
--  the kind subtype tables and pages that schema_tests.sql does not
--  reach. Every post here stays a draft, so the deferred "subtype row
--  before leaving draft" trigger never fires; a post that has to leave
--  draft is inserted inside BEGIN ... ROLLBACK. Runs in the same session,
--  after schema_tests.sql; every value this file inserts carries "r06".
--  A subtype row with a wrong kind points at a post OF that kind, so the
--  composite key (post_id, kind_code) holds and only the kind's CHECK
--  stands between a podcast episode and a success story's post.
-- =====================================================================

-- ---------------------------------------------------------------- rows the tests need
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
VALUES ('r06/cover.jpg', 'r06-cover.jpg', 'image/jpeg', 1000, repeat('6', 64), 'r06 غلاف', 1200, 630);
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256)
VALUES ('r06/infographic.pdf', 'r06-infographic.pdf', 'application/pdf', 1000, repeat('6', 64));
INSERT INTO organizations (name, slug) VALUES ('r06 شركة', 'r06-org');
INSERT INTO categories (name_ar, slug) VALUES ('r06 تصنيف', 'r06-category');
INSERT INTO eras (name_ar) VALUES ('r06 حقبة');
INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('r06 شخصية', 'r06-character', 'r06 دور', '#124FFF');
INSERT INTO character_poses (character_id, pose_type_code, static_media_id)
SELECT c.id, 'smiling', m.id FROM characters c, media_assets m WHERE c.slug = 'r06-character' AND m.path = 'r06/cover.jpg';
INSERT INTO podcast_seasons (season_number, title_ar) VALUES (606, 'r06 موسم');

-- One draft post of every kind (slug r06-<kind>).
INSERT INTO posts (kind_code, category_id, title, slug)
SELECT k.code, c.id, 'r06 ' || k.code, 'r06-' || k.code
FROM post_kinds k, categories c
WHERE c.slug = 'r06-category'
  AND k.code IN ('news', 'episode', 'story', 'product', 'history', 'initiative', 'roundtable', 'awareness', 'infographic');

-- ---------------------------------------------------------------- post kinds
SELECT pg_temp.expect_fail('post_kinds: a kind outside every section must require a category',
  $$INSERT INTO post_kinds (code, name_ar, section_id, requires_category, schema_org_type)
    VALUES ('rsixloose', 'r06 نوع بلا تصنيف', NULL, false, 'Article')$$, 'post_kinds_check');
SELECT pg_temp.expect_fail('post_kinds: code must be lower-case latin letters only',
  $$INSERT INTO post_kinds (code, name_ar, section_id, requires_category, schema_org_type)
    VALUES ('r06kind', 'r06 نوع', NULL, true, 'Article')$$, 'post_kinds_code_check');
-- A kind outside every section that requires a category is accepted.
BEGIN;
INSERT INTO post_kinds (code, name_ar, section_id, requires_category, schema_org_type)
VALUES ('rsixnews', 'r06 نوع إخباري', NULL, true, 'NewsArticle');
ROLLBACK;

-- ---------------------------------------------------------------- posts
SELECT pg_temp.expect_fail('posts: status outside draft/scheduled/published/archived',
  $$INSERT INTO posts (kind_code, category_id, title, slug, status, published_at, cover_media_id, body)
    SELECT 'news', c.id, 'r06 حالة', 'r06-bad-status', 'live', now(), m.id, '<p>نص</p>'
    FROM categories c, media_assets m WHERE c.slug = 'r06-category' AND m.path = 'r06/cover.jpg'$$, 'posts_status_check');
SELECT pg_temp.expect_fail('posts: a scheduled post needs a publish time',
  $$INSERT INTO posts (kind_code, category_id, title, slug, status, published_at, cover_media_id, body)
    SELECT 'news', c.id, 'r06 بلا موعد', 'r06-no-time', 'scheduled', NULL, m.id, '<p>نص</p>'
    FROM categories c, media_assets m WHERE c.slug = 'r06-category' AND m.path = 'r06/cover.jpg'$$, 'posts_check');
SELECT pg_temp.expect_fail('posts: a correction note without its time',
  $$UPDATE posts SET correction_note = 'r06 تصحيح' WHERE slug = 'r06-news'$$, 'posts_check3');
SELECT pg_temp.expect_fail('posts: a correction time without its note',
  $$UPDATE posts SET corrected_at = now() WHERE slug = 'r06-news'$$, 'posts_check3');
SELECT pg_temp.expect_fail('posts: breaking period that ends before the publish time',
  $$UPDATE posts SET published_at = now(), breaking_until = now() - interval '1 hour' WHERE slug = 'r06-news'$$, 'posts_check4');
-- The same post, published with everything it needs, a correction and a breaking period after publication, is accepted.
BEGIN;
UPDATE posts SET status = 'published', published_at = now(), body = '<p>نص</p>',
                 cover_media_id = (SELECT id FROM media_assets WHERE path = 'r06/cover.jpg'),
                 correction_note = 'r06 تصحيح', corrected_at = now(), breaking_until = now() + interval '1 hour'
WHERE slug = 'r06-news';
ROLLBACK;

-- ---------------------------------------------------------------- related posts and revisions
INSERT INTO post_related (post_id, related_post_id)
SELECT a.id, b.id FROM posts a, posts b WHERE a.slug = 'r06-news' AND b.slug = 'r06-story';
SELECT pg_temp.expect_fail('post_related: a post cannot be related to itself',
  $$INSERT INTO post_related (post_id, related_post_id) SELECT id, id FROM posts WHERE slug = 'r06-news'$$, 'post_related_check');
INSERT INTO post_revisions (post_id, revision_no, title, body) SELECT id, 1, title, body FROM posts WHERE slug = 'r06-news';
SELECT pg_temp.expect_fail('post_revisions: revision number zero',
  $$INSERT INTO post_revisions (post_id, revision_no, title, body) SELECT id, 0, title, body FROM posts WHERE slug = 'r06-news'$$, 'post_revisions_revision_no_check');

-- ---------------------------------------------------------------- podcast
SELECT pg_temp.expect_fail('podcast_seasons: season number zero',
  $$INSERT INTO podcast_seasons (season_number, title_ar) VALUES (0, 'r06 موسم صفر')$$, 'podcast_seasons_season_number_check');
SELECT pg_temp.expect_fail('podcast_episodes: subtype row with a kind other than episode',
  $$INSERT INTO podcast_episodes (post_id, kind_code, season_id, episode_number)
    SELECT p.id, 'story', s.id, 1 FROM posts p, podcast_seasons s WHERE p.slug = 'r06-story' AND s.season_number = 606$$, 'podcast_episodes_kind_code_check');
SELECT pg_temp.expect_fail('podcast_episodes: episode number zero',
  $$INSERT INTO podcast_episodes (post_id, season_id, episode_number)
    SELECT p.id, s.id, 0 FROM posts p, podcast_seasons s WHERE p.slug = 'r06-episode' AND s.season_number = 606$$, 'podcast_episodes_episode_number_check');
INSERT INTO podcast_episodes (post_id, season_id, episode_number)
SELECT p.id, s.id, 1 FROM posts p, podcast_seasons s WHERE p.slug = 'r06-episode' AND s.season_number = 606;

-- ---------------------------------------------------------------- success stories
SELECT pg_temp.expect_fail('success_stories: subtype row with a kind other than story',
  $$INSERT INTO success_stories (post_id, kind_code, organization_id)
    SELECT p.id, 'product', o.id FROM posts p, organizations o WHERE p.slug = 'r06-product' AND o.slug = 'r06-org'$$, 'success_stories_kind_code_check');
INSERT INTO success_stories (post_id, organization_id)
SELECT p.id, o.id FROM posts p, organizations o WHERE p.slug = 'r06-story' AND o.slug = 'r06-org';

-- ---------------------------------------------------------------- made in Nablus
SELECT pg_temp.expect_fail('products: subtype row with a kind other than product',
  $$INSERT INTO products (post_id, kind_code, product_name, organization_id)
    SELECT p.id, 'story', 'r06 منتج', o.id FROM posts p, organizations o WHERE p.slug = 'r06-story' AND o.slug = 'r06-org'$$, 'products_kind_code_check');
INSERT INTO products (post_id, product_name, organization_id)
SELECT p.id, 'r06 منتج', o.id FROM posts p, organizations o WHERE p.slug = 'r06-product' AND o.slug = 'r06-org';

-- ---------------------------------------------------------------- Nablus economic history
SELECT pg_temp.expect_fail('history_entries: subtype row with a kind other than history',
  $$INSERT INTO history_entries (post_id, kind_code, era_id)
    SELECT p.id, 'episode', e.id FROM posts p, eras e WHERE p.slug = 'r06-episode' AND e.name_ar = 'r06 حقبة'$$, 'history_entries_kind_code_check');
SELECT pg_temp.expect_fail('history_entries: end year before the start year',
  $$INSERT INTO history_entries (post_id, era_id, year_from, year_to)
    SELECT p.id, e.id, 1950, 1900 FROM posts p, eras e WHERE p.slug = 'r06-history' AND e.name_ar = 'r06 حقبة'$$, 'history_entries_check');
INSERT INTO history_entries (post_id, era_id, year_from, year_to)
SELECT p.id, e.id, 1900, 1950 FROM posts p, eras e WHERE p.slug = 'r06-history' AND e.name_ar = 'r06 حقبة';

-- ---------------------------------------------------------------- initiatives
SELECT pg_temp.expect_fail('initiatives: subtype row with a kind other than initiative',
  $$INSERT INTO initiatives (post_id, kind_code, initiative_name, organizer_organization_id)
    SELECT p.id, 'story', 'r06 مبادرة', o.id FROM posts p, organizations o WHERE p.slug = 'r06-story' AND o.slug = 'r06-org'$$, 'initiatives_kind_code_check');
SELECT pg_temp.expect_fail('initiatives: status other than ongoing or ended',
  $$INSERT INTO initiatives (post_id, initiative_name, organizer_organization_id, status)
    SELECT p.id, 'r06 مبادرة', o.id, 'paused' FROM posts p, organizations o WHERE p.slug = 'r06-initiative' AND o.slug = 'r06-org'$$, 'initiatives_status_check');
INSERT INTO initiatives (post_id, initiative_name, organizer_organization_id, status)
SELECT p.id, 'r06 مبادرة', o.id, 'ended' FROM posts p, organizations o WHERE p.slug = 'r06-initiative' AND o.slug = 'r06-org';

-- ---------------------------------------------------------------- round table
SELECT pg_temp.expect_fail('roundtable_sessions: subtype row with a kind other than roundtable',
  $$INSERT INTO roundtable_sessions (post_id, kind_code, held_on)
    SELECT id, 'episode', DATE '2026-06-06' FROM posts WHERE slug = 'r06-episode'$$, 'roundtable_sessions_kind_code_check');
INSERT INTO roundtable_sessions (post_id, held_on) SELECT id, DATE '2026-06-06' FROM posts WHERE slug = 'r06-roundtable';

-- ---------------------------------------------------------------- awareness
SELECT pg_temp.expect_fail('awareness_cards: subtype row with a kind other than awareness',
  $$INSERT INTO awareness_cards (post_id, kind_code, character_pose_id, awareness_type_code)
    SELECT p.id, 'story', cp.id, 'advice' FROM posts p, character_poses cp JOIN characters c ON c.id = cp.character_id
    WHERE p.slug = 'r06-story' AND c.slug = 'r06-character'$$, 'awareness_cards_kind_code_check');
INSERT INTO awareness_cards (post_id, character_pose_id, awareness_type_code)
SELECT p.id, cp.id, 'advice' FROM posts p, character_poses cp JOIN characters c ON c.id = cp.character_id
WHERE p.slug = 'r06-awareness' AND c.slug = 'r06-character';

-- ---------------------------------------------------------------- infographics
SELECT pg_temp.expect_fail('infographics: subtype row with a kind other than infographic',
  $$INSERT INTO infographics (post_id, kind_code, download_media_id)
    SELECT p.id, 'news', m.id FROM posts p, media_assets m WHERE p.id = (SELECT min(id) FROM posts WHERE kind_code = 'news') AND m.path = 'r06/infographic.pdf'$$, 'infographics_kind_code_check');
INSERT INTO infographics (post_id, download_media_id)
SELECT p.id, m.id FROM posts p, media_assets m WHERE p.slug = 'r06-infographic' AND m.path = 'r06/infographic.pdf';

-- ---------------------------------------------------------------- static pages
SELECT pg_temp.expect_fail('pages: system key must be lower-case latin letters and underscores only',
  $$INSERT INTO pages (system_key, title, slug) VALUES ('About-R', 'r06 صفحة', 'r06-page')$$, 'pages_system_key_check');
INSERT INTO pages (system_key, title, slug) VALUES ('rsix_page', 'r06 صفحة', 'r06-page');
