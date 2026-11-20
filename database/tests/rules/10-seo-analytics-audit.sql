-- =====================================================================
--  Rules of 10-seo-analytics-audit.sql: redirects, search statistics,
--  daily analytics, 404 paths, audit log, menus and home blocks. Each
--  "must fail" case breaks one CHECK and is wrapped in expect_fail();
--  rows the tests need are plain statements. Unique values carry the
--  prefix r10; per-day rows use days in 1910.
-- =====================================================================

-- ---------------------------------------------------------------- redirects
INSERT INTO redirects (from_path, to_path) VALUES ('/r10-old', '/r10-new');
INSERT INTO redirects (from_path, status_code) VALUES ('/r10-gone', 410);
SELECT pg_temp.expect_eq('redirects: a 301 with a target and a 410 without one are accepted',
  (SELECT count(*)::text FROM redirects WHERE from_path IN ('/r10-old', '/r10-gone')), '2');
SELECT pg_temp.expect_fail('redirects: source path without a leading slash refused',
  $$INSERT INTO redirects (from_path, to_path) VALUES ('r10-a', '/r10-b')$$, 'redirects_from_path_check');
SELECT pg_temp.expect_fail('redirects: target that is neither a path nor http(s) refused',
  $$INSERT INTO redirects (from_path, to_path) VALUES ('/r10-a', 'javascript:alert(1)')$$, 'redirects_to_path_check');
SELECT pg_temp.expect_fail('redirects: status code other than 301/302/410 refused',
  $$INSERT INTO redirects (from_path, to_path, status_code) VALUES ('/r10-a', '/r10-b', 307)$$, 'redirects_status_code_check');
SELECT pg_temp.expect_fail('redirects: negative hit count refused',
  $$UPDATE redirects SET hits = -1 WHERE from_path = '/r10-old'$$, 'redirects_hits_check');
SELECT pg_temp.expect_fail('redirects: a 410 with a target refused',
  $$UPDATE redirects SET to_path = '/r10-new' WHERE from_path = '/r10-gone'$$, 'redirects_check');
SELECT pg_temp.expect_fail('redirects: a 301 without a target refused',
  $$INSERT INTO redirects (from_path) VALUES ('/r10-a')$$, 'redirects_check');
SELECT pg_temp.expect_fail('redirects: a path redirecting to itself refused',
  $$INSERT INTO redirects (from_path, to_path) VALUES ('/r10-loop', '/r10-loop')$$, 'redirects_check1');

-- ---------------------------------------------------------------- search statistics
INSERT INTO search_terms (normalized_term) VALUES ('r10 بحث');
INSERT INTO search_stats_daily (term_id, stat_date, searches, zero_result_searches)
SELECT id, '1910-10-01', 3, 3 FROM search_terms WHERE normalized_term = 'r10 بحث';
SELECT pg_temp.expect_fail('search_stats_daily: more zero-result searches than searches refused',
  $$UPDATE search_stats_daily SET zero_result_searches = 4
    WHERE term_id = (SELECT id FROM search_terms WHERE normalized_term = 'r10 بحث')$$, 'search_stats_daily_check');
SELECT pg_temp.expect_fail('search_stats_daily: negative zero-result searches refused',
  $$UPDATE search_stats_daily SET zero_result_searches = -1
    WHERE term_id = (SELECT id FROM search_terms WHERE normalized_term = 'r10 بحث')$$, 'search_stats_daily_zero_result_searches_check');
-- searches < 0 forces zero_result_searches < 0 too (zero <= searches); the searches rule is the one named first.
SELECT pg_temp.expect_fail('search_stats_daily: negative searches refused',
  $$UPDATE search_stats_daily SET searches = -1, zero_result_searches = -1
    WHERE term_id = (SELECT id FROM search_terms WHERE normalized_term = 'r10 بحث')$$, 'search_stats_daily_searches_check');

-- ---------------------------------------------------------------- post views
INSERT INTO posts (kind_code, title, slug) VALUES ('news', 'r10 مسودة', 'r10-post');
SELECT pg_temp.expect_fail('post_views_daily: negative views refused',
  $$INSERT INTO post_views_daily (post_id, stat_date, views)
    SELECT id, '1910-10-01', -1 FROM posts WHERE slug = 'r10-post'$$, 'post_views_daily_views_check');

-- ---------------------------------------------------------------- site statistics
INSERT INTO site_stats_daily (stat_date, page_views, unique_visitors) VALUES ('1910-10-01', 10, 4);
SELECT pg_temp.expect_fail('site_stats_daily: negative unique visitors refused',
  $$UPDATE site_stats_daily SET unique_visitors = -1 WHERE stat_date = '1910-10-01'$$, 'site_stats_daily_unique_visitors_check');
-- page_views < 0 forces unique_visitors < 0 too (visitors <= views); the page-views rule is the one named first.
SELECT pg_temp.expect_fail('site_stats_daily: negative page views refused',
  $$UPDATE site_stats_daily SET page_views = -1, unique_visitors = -1 WHERE stat_date = '1910-10-01'$$, 'site_stats_daily_page_views_check');

-- ---------------------------------------------------------------- traffic, devices, events
SELECT pg_temp.expect_fail('traffic_sources_daily: negative visits refused',
  $$INSERT INTO traffic_sources_daily (stat_date, source_code, visits) VALUES ('1910-10-01', 'direct', -1)$$, 'traffic_sources_daily_visits_check');
INSERT INTO device_stats_daily (stat_date, device_type, visits) VALUES ('1910-10-01', 'mobile', 5);
SELECT pg_temp.expect_fail('device_stats_daily: unknown device type refused',
  $$INSERT INTO device_stats_daily (stat_date, device_type, visits) VALUES ('1910-10-01', 'watch', 1)$$, 'device_stats_daily_device_type_check');
SELECT pg_temp.expect_fail('device_stats_daily: negative visits refused',
  $$UPDATE device_stats_daily SET visits = -1 WHERE stat_date = '1910-10-01' AND device_type = 'mobile'$$, 'device_stats_daily_visits_check');
-- Event codes allow only a-z and "_", so the prefix is spelled out.
INSERT INTO event_types (code, name_ar) VALUES ('rules_ten_event', 'r10 حدث');
SELECT pg_temp.expect_fail('event_types: code with digits or capitals refused',
  $$INSERT INTO event_types (code, name_ar) VALUES ('R10-event', 'r10 حدث آخر')$$, 'event_types_code_check');
SELECT pg_temp.expect_fail('event_stats_daily: negative count refused',
  $$INSERT INTO event_stats_daily (stat_date, event_code, count) VALUES ('1910-10-01', 'rules_ten_event', -1)$$, 'event_stats_daily_count_check');

-- ---------------------------------------------------------------- service contact clicks
INSERT INTO services (group_id, name_ar, slug, summary, price_mode)
SELECT id, 'r10 خدمة', 'r10-service', 'r10', 'on_request' FROM service_groups ORDER BY id LIMIT 1;
SELECT pg_temp.expect_fail('service_contact_clicks_daily: unknown channel refused',
  $$INSERT INTO service_contact_clicks_daily (service_id, channel, stat_date, clicks)
    SELECT id, 'sms', '1910-10-01', 1 FROM services WHERE slug = 'r10-service'$$, 'service_contact_clicks_daily_channel_check');
SELECT pg_temp.expect_fail('service_contact_clicks_daily: negative clicks refused',
  $$INSERT INTO service_contact_clicks_daily (service_id, channel, stat_date, clicks)
    SELECT id, 'whatsapp', '1910-10-01', -1 FROM services WHERE slug = 'r10-service'$$, 'service_contact_clicks_daily_clicks_check');

-- ---------------------------------------------------------------- video plays
INSERT INTO videos (source, display_title) VALUES ('youtube', 'r10 فيديو');
SELECT pg_temp.expect_fail('video_plays_daily: negative plays refused',
  $$INSERT INTO video_plays_daily (video_id, stat_date, plays)
    SELECT id, '1910-10-01', -1 FROM videos WHERE display_title = 'r10 فيديو'$$, 'video_plays_daily_plays_check');

-- ---------------------------------------------------------------- 404 paths
INSERT INTO not_found_paths (path) VALUES ('/r10-missing');
SELECT pg_temp.expect_fail('not_found_paths: zero hits refused',
  $$UPDATE not_found_paths SET hits = 0 WHERE path = '/r10-missing'$$, 'not_found_paths_hits_check');
SELECT pg_temp.expect_fail('not_found_paths: last seen before first seen refused',
  $$UPDATE not_found_paths SET last_seen_at = first_seen_at - interval '1 second' WHERE path = '/r10-missing'$$, 'not_found_paths_check');

-- ---------------------------------------------------------------- audit log
-- Inserts only: audit rows are append-only (trigger), so every refused case is an INSERT.
SELECT pg_temp.expect_fail('audit_log: unknown action refused',
  $$INSERT INTO audit_log (action, subject_table, subject_id) VALUES ('approve', 'posts', 1)$$, 'audit_log_action_check');
SELECT pg_temp.expect_fail('audit_log: subject table without subject id refused',
  $$INSERT INTO audit_log (action, subject_table) VALUES ('update', 'posts')$$, 'audit_log_check');
SELECT pg_temp.expect_fail('audit_log: subject id without subject table refused',
  $$INSERT INTO audit_log (action, subject_id) VALUES ('update', 1)$$, 'audit_log_check');

-- ---------------------------------------------------------------- menus
SELECT pg_temp.expect_fail('menus: unknown menu key refused',
  $$INSERT INTO menus (key, name_ar) VALUES ('sidebar', 'r10 قائمة')$$, 'menus_key_check');
INSERT INTO menu_items (menu_id, label_ar, url)
SELECT id, 'r10 رابط', '/r10-link' FROM menus WHERE key = 'footer_legal';
SELECT pg_temp.expect_fail('menu_items: a link that is its own parent refused',
  $$UPDATE menu_items SET parent_id = id WHERE label_ar = 'r10 رابط'$$, 'menu_items_check1');
SELECT pg_temp.expect_fail('menu_items: url that is neither a path nor http(s) refused',
  $$UPDATE menu_items SET url = 'javascript:alert(1)' WHERE label_ar = 'r10 رابط'$$, 'menu_items_url_check');

-- ---------------------------------------------------------------- home blocks
INSERT INTO home_blocks (block_type, item_limit, sort_order) VALUES ('latest', 6, 91001);
SELECT pg_temp.expect_fail('home_blocks: unknown block type refused',
  $$INSERT INTO home_blocks (block_type, sort_order) VALUES ('banner', 91002)$$, 'home_blocks_block_type_check');
SELECT pg_temp.expect_fail('home_blocks: section block without a section refused',
  $$INSERT INTO home_blocks (block_type, sort_order) VALUES ('section', 91002)$$, 'home_blocks_check1');
SELECT pg_temp.expect_fail('home_blocks: playlist block without a playlist refused',
  $$INSERT INTO home_blocks (block_type, sort_order) VALUES ('playlist', 91002)$$, 'home_blocks_check2');
SELECT pg_temp.expect_fail('home_blocks: ad block without a placement refused',
  $$INSERT INTO home_blocks (block_type, sort_order) VALUES ('ad', 91002)$$, 'home_blocks_check3');
SELECT pg_temp.expect_fail('home_blocks: more than 24 items refused',
  $$UPDATE home_blocks SET item_limit = 25 WHERE sort_order = 91001$$, 'home_blocks_item_limit_check');
SELECT pg_temp.expect_fail('home_blocks: zero items refused',
  $$UPDATE home_blocks SET item_limit = 0 WHERE sort_order = 91001$$, 'home_blocks_item_limit_check');
