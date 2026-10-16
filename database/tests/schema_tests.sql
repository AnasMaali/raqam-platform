-- =====================================================================
--  Raqam schema — constraint & trigger tests
--  Run on a fresh database after raqam_schema.sql:
--    psql -v ON_ERROR_STOP=1 -d raqam_test -f tests/schema_tests.sql
--  Every "must fail" case is wrapped in expect_fail(); every positive
--  case is a plain statement (any error stops the run).
-- =====================================================================
\set QUIET on
\o /dev/null
SET client_min_messages = warning;

CREATE TEMP TABLE test_results (n serial, label text, ok boolean, detail text);

CREATE FUNCTION pg_temp.expect_fail(p_label text, p_sql text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  BEGIN
    EXECUTE p_sql;
  EXCEPTION WHEN others THEN
    INSERT INTO test_results (label, ok, detail) VALUES (p_label, true, SQLERRM);
    RETURN;
  END;
  INSERT INTO test_results (label, ok, detail) VALUES (p_label, false, 'statement succeeded but should have failed');
END $$;

CREATE FUNCTION pg_temp.expect_eq(p_label text, p_actual text, p_expected text) RETURNS void
LANGUAGE sql AS $$
  INSERT INTO test_results (label, ok, detail)
  VALUES (p_label, p_actual IS NOT DISTINCT FROM p_expected,
          format('expected %s, got %s', p_expected, p_actual));
$$;

-- ---------------------------------------------------------------- users & roles
INSERT INTO users (user_type, name, email, password_hash)
VALUES ('staff', 'Admin', 'Admin@Raqam.ps', 'x'), ('customer', 'Ali', 'ali@example.com', 'x');
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u, roles r WHERE u.email = 'admin@raqam.ps' AND r.code = 'admin';
SELECT pg_temp.expect_eq('citext: e-mail lookup is case-insensitive',
  (SELECT name FROM users WHERE email = 'ADMIN@raqam.ps'), 'Admin');
SELECT pg_temp.expect_fail('roles: customer cannot hold a role',
  $$INSERT INTO user_roles SELECT u.id, r.id FROM users u, roles r WHERE u.user_type = 'customer' AND r.code = 'admin'$$);
SELECT pg_temp.expect_fail('users: duplicate e-mail with other case',
  $$INSERT INTO users (user_type, name, email, password_hash) VALUES ('staff', 'X', 'ADMIN@raqam.ps', 'x')$$);
SELECT pg_temp.expect_fail('verification: more than 5 attempts',
  $$INSERT INTO verification_codes (purpose, email, code_hash, attempts, expires_at) VALUES ('admin_login', 'admin@raqam.ps', 'h', 6, now() + interval '10 min')$$);
SELECT pg_temp.expect_fail('login_attempts: failed attempt needs a reason',
  $$INSERT INTO login_attempts (email, succeeded) VALUES ('admin@raqam.ps', false)$$);

-- ---------------------------------------------------------------- domains
SELECT pg_temp.expect_fail('slug: tashkeel refused',
  $$INSERT INTO tags (name_ar, slug) VALUES ('اقتصاد', 'اقْتِصَاد')$$);
SELECT pg_temp.expect_fail('slug: spaces refused',
  $$INSERT INTO tags (name_ar, slug) VALUES ('اقتصاد', 'اقتصاد فلسطين')$$);
SELECT pg_temp.expect_fail('phone: must be E.164',
  $$INSERT INTO customers (name, email, phone) VALUES ('A', 'a@b.co', '0599123456')$$);
INSERT INTO tags (name_ar, slug) VALUES ('اقتصاد', 'اقتصاد');

-- ---------------------------------------------------------------- media
SELECT pg_temp.expect_fail('media: public image without alt text',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, width, height)
    VALUES ('a/1.jpg', '1.jpg', 'image/jpeg', 1000, repeat('a', 64), 800, 600)$$);
SELECT pg_temp.expect_fail('media: uploaded clip longer than 90 s',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, duration_seconds, width, height)
    VALUES ('v/1.mp4', '1.mp4', 'video/mp4', 1000, repeat('b', 64), 120, 1920, 1080)$$);
SELECT pg_temp.expect_fail('media: image over 10 MB',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
    VALUES ('a/big.jpg', 'big.jpg', 'image/jpeg', 11 * 1024 * 1024, repeat('c', 64), 'alt', 4000, 3000)$$);
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
VALUES ('a/cover.jpg', 'cover.jpg', 'image/jpeg', 200000, repeat('d', 64), 'صورة الغلاف', 1600, 900),
       ('a/pose.png',  'pose.png',  'image/png',  50000,  repeat('e', 64), 'شخصية رقم', 800, 800),
       ('a/pdf.pdf',   'inv.pdf',   'application/pdf', 50000, repeat('f', 64), NULL, NULL, NULL);
SELECT pg_temp.expect_fail('media: raster image without dimensions',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text)
    VALUES ('a/nodim.jpg', 'nodim.jpg', 'image/jpeg', 1000, repeat('7', 64), 'alt')$$);
SELECT pg_temp.expect_fail('media: video without dimensions',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, duration_seconds)
    VALUES ('v/nodim.mp4', 'nodim.mp4', 'video/mp4', 1000, repeat('8', 64), 30)$$);
SELECT pg_temp.expect_fail('media: width without height',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width)
    VALUES ('a/half.svg', 'half.svg', 'image/svg+xml', 1000, repeat('9', 64), 'alt', 100)$$);
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text)
VALUES ('a/logo.svg', 'logo.svg', 'image/svg+xml', 3000, repeat('0', 64), 'شعار رقم'),
       ('a/raqam.riv', 'raqam.riv', 'application/x-rive', 90000, repeat('3', 64), NULL);
SELECT pg_temp.expect_eq('media: SVG and Rive need no dimensions',
  (SELECT count(*)::text FROM media_assets WHERE path IN ('a/logo.svg', 'a/raqam.riv')), '2');

-- ---------------------------------------------------------------- taxonomy
INSERT INTO categories (parent_id, name_ar, slug)
SELECT id, 'بلدية نابلس', 'بلدية-نابلس' FROM categories WHERE slug = 'محلي-نابلس';
SELECT pg_temp.expect_fail('categories: only one sub-level',
  $$INSERT INTO categories (parent_id, name_ar, slug)
    SELECT id, 'مستوى ثالث', 'مستوى-ثالث' FROM categories WHERE slug = 'بلدية-نابلس'$$);

-- ---------------------------------------------------------------- posts
INSERT INTO posts (kind_code, title, slug) VALUES ('news', 'مسودة بدون تصنيف', 'مسودة');
SELECT pg_temp.expect_fail('posts: news cannot leave draft without a category',
  $$INSERT INTO posts (kind_code, title, slug, body, status, published_at, cover_media_id)
    SELECT 'news', 't', 't', '<p>x</p>', 'published', now(), id FROM media_assets WHERE path = 'a/cover.jpg'$$);
SELECT pg_temp.expect_fail('posts: cannot publish without main image',
  $$INSERT INTO posts (kind_code, category_id, title, slug, body, status, published_at)
    SELECT 'news', id, 't', 't', '<p>x</p>', 'published', now() FROM categories WHERE slug = 'فلسطين'$$);
SELECT pg_temp.expect_fail('posts: cannot publish without text',
  $$INSERT INTO posts (kind_code, category_id, title, slug, status, published_at, cover_media_id)
    SELECT 'news', c.id, 't', 't', 'published', now(), m.id FROM categories c, media_assets m
    WHERE c.slug = 'فلسطين' AND m.path = 'a/cover.jpg'$$);
INSERT INTO posts (kind_code, category_id, title, slug, body, status, published_at, cover_media_id)
SELECT 'news', c.id, 'ارتفاع الدولار', 'ارتفاع-الدولار', '<p>نص</p>', 'published', now(), m.id
FROM categories c, media_assets m WHERE c.slug = 'أسواق-ومال' AND m.path = 'a/cover.jpg';
SELECT pg_temp.expect_fail('posts: kind cannot change',
  $$UPDATE posts SET kind_code = 'report' WHERE slug = 'ارتفاع-الدولار'$$);

-- Subtype integrity
SELECT pg_temp.expect_fail('subtype: episode row cannot attach to a news post',
  $$INSERT INTO podcast_seasons (season_number) VALUES (99);
    INSERT INTO podcast_episodes (post_id, season_id, episode_number)
    SELECT p.id, s.id, 1 FROM posts p, podcast_seasons s WHERE p.slug = 'ارتفاع-الدولار' AND s.season_number = 99$$);

-- Deferred check: a published episode must have its podcast_episodes row (checked at commit)
SELECT pg_temp.expect_fail('subtype: published episode without episode row',
  $$SET CONSTRAINTS posts_subtype_present IMMEDIATE;
    INSERT INTO posts (kind_code, title, slug, body, status, published_at, cover_media_id)
    SELECT 'episode', 'حلقة', 'حلقة', '<p>x</p>', 'published', now(), id FROM media_assets WHERE path = 'a/cover.jpg'$$);

BEGIN;
  INSERT INTO podcast_seasons (season_number, title_ar) VALUES (1, 'الموسم الأول');
  INSERT INTO posts (kind_code, title, slug, body, status, published_at, cover_media_id)
  SELECT 'episode', 'الحلقة الأولى', 'الحلقة-الأولى', '<p>x</p>', 'published', now(), id
  FROM media_assets WHERE path = 'a/cover.jpg';
  INSERT INTO podcast_episodes (post_id, season_id, episode_number)
  SELECT p.id, s.id, 1 FROM posts p, podcast_seasons s WHERE p.slug = 'الحلقة-الأولى' AND s.season_number = 1;
COMMIT;
SELECT pg_temp.expect_fail('podcast: duplicate episode number in a season',
  $$INSERT INTO posts (kind_code, title, slug) VALUES ('episode', 'مكرر', 'مكرر');
    INSERT INTO podcast_episodes (post_id, season_id, episode_number)
    SELECT p.id, s.id, 1 FROM posts p, podcast_seasons s WHERE p.slug = 'مكرر' AND s.season_number = 1$$);

-- Awareness card: character is reached through the pose (no duplicate character_id)
INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('رَقَم', 'raqam', 'الراوي', '#0B3D5C');
INSERT INTO character_poses (character_id, pose_type_code, static_media_id)
SELECT c.id, 'explaining', m.id FROM characters c, media_assets m WHERE c.slug = 'raqam' AND m.path = 'a/pose.png';
SELECT pg_temp.expect_fail('characters: same pose twice for one character',
  $$INSERT INTO character_poses (character_id, pose_type_code, static_media_id)
    SELECT c.id, 'explaining', m.id FROM characters c, media_assets m WHERE c.slug = 'raqam' AND m.path = 'a/pose.png'$$);

-- ---------------------------------------------------------------- video
SELECT pg_temp.expect_fail('video: upload without a file',
  $$INSERT INTO videos (source, display_title) VALUES ('upload', 'مقطع')$$);
INSERT INTO videos (source) VALUES ('youtube');
INSERT INTO youtube_videos (video_id, youtube_id, source_title)
SELECT max(id), 'dQw4w9WgXcQ', 'Episode 1' FROM videos;
SELECT pg_temp.expect_fail('video: bad YouTube id',
  $$INSERT INTO videos (source) VALUES ('youtube');
    INSERT INTO youtube_videos (video_id, youtube_id, source_title) SELECT max(id), 'bad id!', 'x' FROM videos$$);
INSERT INTO playlists (name_ar, slug) VALUES ('الموسم الأول', 'season-1');
WITH v AS (INSERT INTO videos (source) VALUES ('youtube') RETURNING id)
INSERT INTO youtube_videos (video_id, youtube_id, source_title) SELECT id, 'abcdefghijk', 'Episode 2' FROM v;
INSERT INTO playlist_videos (playlist_id, video_id, sort_order)
SELECT p.id, y.video_id, row_number() OVER (ORDER BY y.video_id) FROM playlists p, youtube_videos y;
-- Deferrable unique: swap positions 1 <-> 2 inside one transaction
BEGIN;
  UPDATE playlist_videos SET sort_order = 3 - sort_order;
COMMIT;
SELECT pg_temp.expect_eq('playlist: swap positions (deferrable unique)',
  (SELECT string_agg(video_id::text, ',' ORDER BY sort_order) FROM playlist_videos),
  (SELECT string_agg(video_id::text, ',' ORDER BY video_id DESC) FROM youtube_videos));
SELECT pg_temp.expect_fail('playlist: same position twice is refused at commit',
  $$SET CONSTRAINTS playlist_videos_position_uq IMMEDIATE;
    UPDATE playlist_videos SET sort_order = 1$$);

-- ---------------------------------------------------------------- currency
INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at) VALUES
  ('USD', 'bank_of_israel', 3.60, now() - interval '1 day'),
  ('USD', 'bank_of_israel', 3.75, now());
SELECT pg_temp.expect_eq('rate_board: buy = mid - 0.5%',
  (SELECT buy_rate::text FROM rate_board WHERE currency_code = 'USD'), '3.731250');
SELECT pg_temp.expect_eq('rate_board: change vs previous business day',
  (SELECT change_pct::text FROM rate_board WHERE currency_code = 'USD'), '4.17');
INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate, valid_until)
VALUES ('USD', 3.70, 3.80, now() + interval '2 hours');
SELECT pg_temp.expect_eq('rate_board: manual override wins',
  (SELECT buy_rate::text || '/' || is_manual FROM rate_board WHERE currency_code = 'USD'), '3.7000000000/true');
SELECT pg_temp.expect_fail('rate_overrides: overlapping periods refused (EXCLUDE)',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate, valid_from)
    VALUES ('USD', 3.6, 3.9, now() + interval '1 hour')$$);
SELECT pg_temp.expect_fail('rate_overrides: sell below buy',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate, valid_from)
    VALUES ('JOD', 5.3, 5.2, now())$$);
SELECT pg_temp.expect_fail('exchange_rates: ILS against itself',
  $$INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at) VALUES ('ILS', 'bank_of_israel', 1, now())$$);
SELECT pg_temp.expect_eq('pegs: AED derived from USD (3.75 / 3.6725)',
  (SELECT round(mid_rate, 4)::text || '/' || is_pegged FROM rate_board WHERE currency_code = 'AED'), '1.0211/true');
SELECT pg_temp.expect_eq('pegs: SAR derived from USD (3.75 / 3.75)',
  (SELECT round(mid_rate, 4)::text FROM rate_board WHERE currency_code = 'SAR'), '1.0000');
SELECT pg_temp.expect_fail('pegs: a pegged currency cannot be fetched separately',
  $$INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at) VALUES ('AED', 'bank_of_israel', 1.02, now())$$);

-- ---------------------------------------------------------------- weather
SELECT pg_temp.expect_fail('weather: only one default city',
  $$UPDATE weather_locations SET is_default = true WHERE city_id = (SELECT id FROM cities WHERE slug = 'جنين')$$);
SELECT pg_temp.expect_fail('weather: unknown condition code',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, condition_code, is_day)
    SELECT id, 'openweathermap', now(), 22, 9999, true FROM cities WHERE slug = 'نابلس'$$);

-- ---------------------------------------------------------------- feature flags
SELECT pg_temp.expect_fail('flags: payments need booking on',
  $$UPDATE feature_flags SET is_enabled = true WHERE key = 'online_payments'$$);
UPDATE feature_flags SET is_enabled = true WHERE key = 'booking';
UPDATE feature_flags SET is_enabled = true WHERE key = 'online_payments';
SELECT pg_temp.expect_fail('flags: cannot switch booking off while payments on',
  $$UPDATE feature_flags SET is_enabled = false WHERE key = 'booking'$$);
SELECT pg_temp.expect_fail('providers: two primary currency providers',
  $$UPDATE data_providers SET is_active = true, is_backup = false WHERE code = 'exchangerate_api'$$);

-- ---------------------------------------------------------------- services
SELECT pg_temp.expect_fail('services: "on request" must not have a price',
  $$INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code)
    VALUES (1, 'x', 'x', 'x', 'on_request', 1000, 'USD')$$);
SELECT pg_temp.expect_fail('services: sale price must be lower',
  $$INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code, sale_price_amount, sale_ends_at)
    VALUES (1, 'y', 'y', 'y', 'fixed', 1000, 'USD', 1200, now() + interval '7 days')$$);
INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code, price_note)
VALUES (1, 'حلقة بودكاست', 'حلقة-بودكاست', 'استضافة في حلقة', 'fixed', 30000, 'USD', 'للحلقة');

-- ---------------------------------------------------------------- bookings
INSERT INTO customers (name, email, phone) VALUES ('سامي', 'sami@example.com', '+970599123456');
INSERT INTO bookings (service_id, customer_id, preferred_contact, details, price_amount, currency_code,
                      access_token_hash, consent_at)
SELECT s.id, c.id, 'whatsapp', 'تفاصيل', s.price_amount, s.currency_code, repeat('1', 64), now()
FROM services s, customers c WHERE s.slug = 'حلقة-بودكاست' AND c.email = 'sami@example.com';
SELECT pg_temp.expect_eq('bookings: reference generated',
  (SELECT reference FROM bookings LIMIT 1), 'RQ-' || local_year() || '-00001');
SELECT pg_temp.expect_eq('bookings: initial history row written',
  (SELECT count(*)::text FROM booking_status_history), '1');
SELECT pg_temp.expect_fail('bookings: direct status update refused',
  $$UPDATE bookings SET status_code = 'confirmed'$$);
SELECT pg_temp.expect_fail('bookings: illegal transition new -> published',
  $$INSERT INTO booking_status_history (booking_id, status_code) SELECT id, 'published' FROM bookings LIMIT 1$$);
INSERT INTO booking_status_history (booking_id, status_code, note) SELECT id, 'contacted', 'اتصلنا' FROM bookings LIMIT 1;
SELECT pg_temp.expect_eq('bookings: legal transition applied',
  (SELECT status_code FROM bookings LIMIT 1), 'contacted');

-- ---------------------------------------------------------------- payments & invoices
INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status,
                      idempotency_key, paid_at)
SELECT id, 'fake', 1, 'ORD-1', 30000, 'USD', 'captured', 'bk1-a1', now() FROM bookings LIMIT 1;
SELECT pg_temp.expect_fail('payments: second successful payment for one booking',
  $$INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status, idempotency_key, paid_at)
    SELECT id, 'fake', 2, 'ORD-2', 30000, 'USD', 'captured', 'bk1-a2', now() FROM bookings LIMIT 1$$);
SELECT pg_temp.expect_fail('payments: same idempotency key twice',
  $$INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, idempotency_key)
    SELECT id, 'fake', 3, 'ORD-3', 30000, 'USD', 'bk1-a1' FROM bookings LIMIT 1$$);
INSERT INTO refunds (payment_id, amount, status) SELECT id, 10000, 'succeeded' FROM payments LIMIT 1;
SELECT pg_temp.expect_fail('refunds: total cannot exceed payment',
  $$INSERT INTO refunds (payment_id, amount) SELECT id, 25000 FROM payments LIMIT 1$$);

INSERT INTO invoices (booking_id, payment_id, billed_name, billed_email, currency_code, tax_rate_bp)
SELECT b.id, p.id, 'سامي', 'sami@example.com', 'USD', 1600 FROM bookings b, payments p LIMIT 1;
INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount)
SELECT id, 1, 'حلقة بودكاست', 1, 30000 FROM invoices;
SELECT pg_temp.expect_eq('invoices: number generated',
  (SELECT number FROM invoices), 'INV-' || local_year() || '-00001');
SELECT pg_temp.expect_eq('invoices: totals computed by view (16% VAT)',
  (SELECT subtotal || '/' || tax_amount || '/' || total_amount FROM invoice_totals), '30000/4800/34800');
SELECT pg_temp.expect_fail('invoices: billed data immutable',
  $$UPDATE invoices SET billed_name = 'غيره'$$);
UPDATE invoices SET pdf_media_id = (SELECT id FROM media_assets WHERE path = 'a/pdf.pdf');
SELECT pg_temp.expect_fail('invoices: PDF attached only once',
  $$UPDATE invoices SET pdf_media_id = (SELECT id FROM media_assets WHERE path = 'a/cover.jpg')$$);
SELECT pg_temp.expect_fail('invoices: cannot delete',
  $$DELETE FROM invoices$$);
SELECT pg_temp.expect_fail('invoice_lines: cannot change',
  $$UPDATE invoice_lines SET unit_amount = 1$$);

-- ---------------------------------------------------------------- chatbot, menus, home, newsletter
INSERT INTO faqs (question, answer) VALUES ('كيف أعلن؟', 'تواصل معنا');
SELECT pg_temp.expect_fail('bot: FAQ-backed entry must not repeat the text',
  $$INSERT INTO bot_entries (faq_id, question, answer) SELECT id, 'q', 'a' FROM faqs LIMIT 1$$);
INSERT INTO bot_entries (faq_id) SELECT id FROM faqs LIMIT 1;
SELECT pg_temp.expect_fail('bot: service action needs a service',
  $$INSERT INTO bot_entries (question, answer, action_type) VALUES ('q', 'a', 'service')$$);
SELECT pg_temp.expect_fail('bot: visitor message over 500 chars',
  $$INSERT INTO bot_conversations (session_hash) VALUES (repeat('2', 64));
    INSERT INTO bot_messages (conversation_id, role, content) SELECT max(id), 'user', repeat('ا', 501) FROM bot_conversations$$);

SELECT pg_temp.expect_fail('menu: item with two targets',
  $$INSERT INTO menu_items (menu_id, label_ar, url, section_id) VALUES (1, 'x', '/x', 1)$$);
INSERT INTO menu_items (menu_id, label_ar, section_id) VALUES (1, 'البودكاست', 1);
SELECT pg_temp.expect_fail('home: category block without category',
  $$INSERT INTO home_blocks (block_type, sort_order) VALUES ('category', 1)$$);
SELECT pg_temp.expect_fail('newsletter: issue week must start on Saturday',
  $$INSERT INTO newsletter_issues (week_start, subject) VALUES ('2026-10-12', 'x')$$);
SELECT pg_temp.expect_fail('subscribers: active without confirmation',
  $$INSERT INTO subscribers (email, status) VALUES ('n@example.com', 'active')$$);

-- ---------------------------------------------------------------- audit log
INSERT INTO audit_log (actor_user_id, action, subject_table, subject_id)
SELECT id, 'publish', 'posts', 2 FROM users WHERE user_type = 'staff';
INSERT INTO audit_log_changes (audit_id, field, old_value, new_value)
SELECT id, 'status', 'draft', 'published' FROM audit_log;
SELECT pg_temp.expect_fail('audit: UPDATE refused', $$UPDATE audit_log SET action = 'update'$$);
SELECT pg_temp.expect_fail('audit: DELETE refused', $$DELETE FROM audit_log$$);
SELECT pg_temp.expect_fail('audit: change rows immutable', $$UPDATE audit_log_changes SET new_value = 'x'$$);
SELECT pg_temp.expect_fail('audit: pruning young rows refused',
  $$SET LOCAL raqam.audit_prune = 'on'; DELETE FROM audit_log$$);

-- ---------------------------------------------------------------- analytics
INSERT INTO post_views_daily SELECT id, d, v FROM posts, (VALUES (current_date - 1, 40), (current_date, 60)) x(d, v) WHERE slug = 'ارتفاع-الدولار';
REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals;
SELECT pg_temp.expect_eq('analytics: total views from materialized view',
  (SELECT total_views::text FROM post_view_totals t JOIN posts p ON p.id = t.post_id WHERE p.slug = 'ارتفاع-الدولار'), '100');
SELECT pg_temp.expect_fail('analytics: unique visitors > page views',
  $$INSERT INTO site_stats_daily VALUES (current_date, 10, 11)$$);

-- ---------------------------------------------------------------- updated_at trigger
UPDATE posts SET title = title || ' ' WHERE slug = 'مسودة';
SELECT pg_temp.expect_eq('updated_at refreshed by trigger',
  (SELECT (updated_at >= created_at)::text FROM posts WHERE slug = 'مسودة'), 'true');

-- ---------------------------------------------------------------- fixes from the 2026-10-12 review
-- Arabic fallback search: hamza variant + missing letter still find the article
SELECT pg_temp.expect_eq('search: «إرتفاع الدولار» finds «ارتفاع الدولار»',
  (SELECT count(*)::text FROM posts WHERE status = 'published'
     AND search_text LIKE '%' || normalize_ar('إرتفاع الدولار') || '%'), '1');
SELECT pg_temp.expect_eq('search: typo «الدولر» still matches (trigram)',
  (SELECT count(*)::text FROM posts WHERE status = 'published'
     AND word_similarity(normalize_ar('الدولر'), search_text) > 0.5), '1');

-- Images / videos used inside the body are protected and traceable
INSERT INTO post_body_media (post_id, media_id)
SELECT p.id, m.id FROM posts p, media_assets m WHERE p.slug = 'ارتفاع-الدولار' AND m.path = 'a/pose.png';
SELECT pg_temp.expect_fail('body media: image used in an article cannot be deleted',
  $$DELETE FROM media_assets WHERE path = 'a/pose.png'$$);
INSERT INTO post_body_videos (post_id, video_id)
SELECT p.id, y.video_id FROM posts p, youtube_videos y WHERE p.slug = 'ارتفاع-الدولار' AND y.youtube_id = 'dQw4w9WgXcQ';
SELECT pg_temp.expect_eq('video_usage: body embed is listed',
  (SELECT usage FROM video_usage u JOIN youtube_videos y ON y.video_id = u.video_id WHERE y.youtube_id = 'dQw4w9WgXcQ'), 'body');

-- House ad for empty slots
SELECT pg_temp.expect_fail('house ad: needs at least one image',
  $$INSERT INTO house_ads (placement_code) VALUES ('sidebar_mpu')$$);
INSERT INTO house_ads (placement_code, desktop_media_id)
SELECT 'sidebar_mpu', id FROM media_assets WHERE path = 'a/cover.jpg';

-- Team page ordering
INSERT INTO authors (name, slug, job_title, team_sort_order) VALUES ('محرر رقم', 'editor', 'رئيس التحرير', 1);
SELECT pg_temp.expect_fail('team: negative order refused',
  $$INSERT INTO authors (name, slug, team_sort_order) VALUES ('x', 'x', -1)$$);

-- ---------------------------------------------------------------- queue bookkeeping (Laravel)
INSERT INTO failed_jobs (uuid, connection, queue, payload, exception)
VALUES ('0192f0c4-5b7e-7c1a-9d3e-2f4a6b8c0d1e', 'redis', 'default', '{}', 'RuntimeException');
SELECT pg_temp.expect_fail('failed_jobs: same uuid twice',
  $$INSERT INTO failed_jobs (uuid, connection, queue, payload, exception)
    VALUES ('0192f0c4-5b7e-7c1a-9d3e-2f4a6b8c0d1e', 'redis', 'default', '{}', 'x')$$);
SELECT pg_temp.expect_fail('job_batches: negative pending count',
  $$INSERT INTO job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, created_at)
    VALUES ('0192f0c4-5b7e-7c1a-9d3e-2f4a6b8c0d1f', 'newsletter', 1, -1, 0, '[]', 1760000000)$$);

-- ---------------------------------------------------------------- report
\o
\set QUIET off
SELECT n AS "#", CASE WHEN ok THEN 'PASS' ELSE 'FAIL' END AS result, label, left(detail, 90) AS detail
FROM test_results ORDER BY n;
SELECT count(*) FILTER (WHERE ok) AS passed, count(*) FILTER (WHERE NOT ok) AS failed FROM test_results;
