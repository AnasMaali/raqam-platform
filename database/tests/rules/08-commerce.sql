-- =====================================================================
--  Raqam schema — rules of 08-commerce.sql (ads, services, bookings,
--  payments, invoices, refunds) that schema_tests.sql does not break.
--  Runs after schema_tests.sql in the same session; every unique value
--  carries "r08". All amounts are made up.
-- =====================================================================

-- ---------------------------------------------------------------- rows the tests need
INSERT INTO organizations (name, slug) VALUES ('شركة الاختبار r08', 'r08-org-a');
INSERT INTO advertisers (organization_id, contact_name)
SELECT id, 'r08 contact' FROM organizations WHERE slug = 'r08-org-a';
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, width, height, alt_text)
VALUES ('r08/ad-desktop.jpg', 'ad-desktop.jpg', 'image/jpeg', 1000, encode(sha256('r08-media-1'::bytea), 'hex'), 970, 90, 'r08 banner');

-- ---------------------------------------------------------------- ad placements (seeded rows, changed only inside expect_fail)
SELECT pg_temp.expect_fail('ad_placements: unknown slot code',
  $$INSERT INTO ad_placements (code, name_ar) VALUES ('r08_slot', 'r08')$$, 'ad_placements_code_check');
SELECT pg_temp.expect_fail('ad_placements: desktop width must be positive',
  $$UPDATE ad_placements SET desktop_width = 0 WHERE code = 'sidebar_mpu'$$, 'ad_placements_desktop_width_check');
SELECT pg_temp.expect_fail('ad_placements: desktop height must be positive',
  $$UPDATE ad_placements SET desktop_height = 0 WHERE code = 'sidebar_mpu'$$, 'ad_placements_desktop_height_check');
SELECT pg_temp.expect_fail('ad_placements: mobile width must be positive',
  $$UPDATE ad_placements SET mobile_width = 0 WHERE code = 'sidebar_mpu'$$, 'ad_placements_mobile_width_check');
SELECT pg_temp.expect_fail('ad_placements: mobile height must be positive',
  $$UPDATE ad_placements SET mobile_height = -1 WHERE code = 'sidebar_mpu'$$, 'ad_placements_mobile_height_check');
SELECT pg_temp.expect_fail('ad_placements: empty slot behaviour is house or hide',
  $$UPDATE ad_placements SET empty_behavior = 'none' WHERE code = 'sidebar_mpu'$$, 'ad_placements_empty_behavior_check');
SELECT pg_temp.expect_fail('ad_placements: popup frequency cap must be positive',
  $$UPDATE ad_placements SET frequency_cap_hours = 0 WHERE code = 'popup'$$, 'ad_placements_frequency_cap_hours_check');
SELECT pg_temp.expect_fail('ad_placements: only the popup has a frequency cap',
  $$UPDATE ad_placements SET frequency_cap_hours = 12 WHERE code = 'sidebar_mpu'$$, 'ad_placements_check');

-- ---------------------------------------------------------------- ad campaigns
INSERT INTO ad_campaigns (advertiser_id, name, target_url, starts_at, ends_at, priority, status)
SELECT a.id, 'r08 campaign', 'https://example.com/r08', now(), now() + interval '30 days', 50, 'active'
FROM advertisers a JOIN organizations o ON o.id = a.organization_id WHERE o.slug = 'r08-org-a';
SELECT pg_temp.expect_fail('ad_campaigns: end must be after the start',
  $$INSERT INTO ad_campaigns (advertiser_id, name, target_url, starts_at, ends_at)
    SELECT a.id, 'r08 backwards', 'https://example.com/r08', now(), now() - interval '1 day'
    FROM advertisers a JOIN organizations o ON o.id = a.organization_id WHERE o.slug = 'r08-org-a'$$, 'ad_campaigns_check');
SELECT pg_temp.expect_fail('ad_campaigns: an end equal to the start is refused',
  $$UPDATE ad_campaigns SET ends_at = starts_at WHERE name = 'r08 campaign'$$, 'ad_campaigns_check');
SELECT pg_temp.expect_fail('ad_campaigns: priority below 1',
  $$UPDATE ad_campaigns SET priority = 0 WHERE name = 'r08 campaign'$$, 'ad_campaigns_priority_check');
SELECT pg_temp.expect_fail('ad_campaigns: priority above 100',
  $$UPDATE ad_campaigns SET priority = 101 WHERE name = 'r08 campaign'$$, 'ad_campaigns_priority_check');
SELECT pg_temp.expect_fail('ad_campaigns: "ended" is not a stored status',
  $$UPDATE ad_campaigns SET status = 'ended' WHERE name = 'r08 campaign'$$, 'ad_campaigns_status_check');

-- ---------------------------------------------------------------- ad creatives, placements, daily stats
INSERT INTO ad_creatives (campaign_id, device, media_id)
SELECT c.id, 'desktop', m.id FROM ad_campaigns c, media_assets m
WHERE c.name = 'r08 campaign' AND m.path = 'r08/ad-desktop.jpg';
SELECT pg_temp.expect_fail('ad_creatives: device is desktop or mobile',
  $$INSERT INTO ad_creatives (campaign_id, device, media_id)
    SELECT c.id, 'tablet', m.id FROM ad_campaigns c, media_assets m
    WHERE c.name = 'r08 campaign' AND m.path = 'r08/ad-desktop.jpg'$$, 'ad_creatives_device_check');
INSERT INTO ad_campaign_placements (campaign_id, placement_code)
SELECT id, 'in_article' FROM ad_campaigns WHERE name = 'r08 campaign';
INSERT INTO ad_stats_daily (campaign_id, placement_code, stat_date, impressions, clicks)
SELECT id, 'in_article', DATE '2026-01-08', 0, 0 FROM ad_campaigns WHERE name = 'r08 campaign';
SELECT pg_temp.expect_fail('ad_stats_daily: negative impressions',
  $$UPDATE ad_stats_daily SET impressions = -1
    WHERE campaign_id = (SELECT id FROM ad_campaigns WHERE name = 'r08 campaign')$$, 'ad_stats_daily_impressions_check');
SELECT pg_temp.expect_fail('ad_stats_daily: negative clicks',
  $$UPDATE ad_stats_daily SET clicks = -1
    WHERE campaign_id = (SELECT id FROM ad_campaigns WHERE name = 'r08 campaign')$$, 'ad_stats_daily_clicks_check');

-- ---------------------------------------------------------------- house ads
SELECT pg_temp.expect_fail('house_ads: target is a site path or an http(s) address',
  $$INSERT INTO house_ads (placement_code, desktop_media_id, target_path)
    SELECT 'between_sections', id, 'advertise' FROM media_assets WHERE path = 'r08/ad-desktop.jpg'$$, 'house_ads_target_path_check');
SELECT pg_temp.expect_fail('house_ads: a javascript: target is refused',
  $$INSERT INTO house_ads (placement_code, desktop_media_id, target_path)
    SELECT 'between_sections', id, 'javascript:alert(1)' FROM media_assets WHERE path = 'r08/ad-desktop.jpg'$$, 'house_ads_target_path_check');

-- ---------------------------------------------------------------- services
INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code)
SELECT id, 'خدمة r08', 'r08-service-a', 'r08 summary', 'fixed', 12345, 'USD' FROM service_groups WHERE name_ar = 'إعلانات';
SELECT pg_temp.expect_fail('services: unknown price mode',
  $$INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code)
    SELECT id, 'r08', 'r08-service-mode', 'r08', 'monthly', 12345, 'USD' FROM service_groups WHERE name_ar = 'إعلانات'$$, 'services_price_mode_check');
SELECT pg_temp.expect_fail('services: a price needs its currency',
  $$INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount)
    SELECT id, 'r08', 'r08-service-cur', 'r08', 'fixed', 12345 FROM service_groups WHERE name_ar = 'إعلانات'$$, 'services_check1');
SELECT pg_temp.expect_fail('services: a currency without a price is refused',
  $$UPDATE services SET price_mode = 'on_request', price_amount = NULL WHERE slug = 'r08-service-a'$$, 'services_check1');
SELECT pg_temp.expect_fail('services: an offer price needs its end date',
  $$UPDATE services SET sale_price_amount = 9999 WHERE slug = 'r08-service-a'$$, 'services_check2');
SELECT pg_temp.expect_fail('services: an offer end date needs its price',
  $$UPDATE services SET sale_ends_at = now() + interval '7 days' WHERE slug = 'r08-service-a'$$, 'services_check2');
UPDATE services SET sale_price_amount = 9999, sale_ends_at = now() + interval '7 days' WHERE slug = 'r08-service-a';
SELECT pg_temp.expect_eq('services: a complete lower offer is accepted',
  (SELECT sale_price_amount::text FROM services WHERE slug = 'r08-service-a'), '9999');

-- ---------------------------------------------------------------- booking statuses & transitions
SELECT pg_temp.expect_fail('booking_statuses: code is lower-case letters and underscores',
  $$INSERT INTO booking_statuses (code, name_ar) VALUES ('R08-Status', 'r08 حالة')$$, 'booking_statuses_code_check');
SELECT pg_temp.expect_fail('booking_status_transitions: a status cannot move to itself',
  $$INSERT INTO booking_status_transitions (from_code, to_code) VALUES ('confirmed', 'confirmed')$$, 'booking_status_transitions_check');

-- ---------------------------------------------------------------- bookings
INSERT INTO customers (name, email, phone) VALUES ('عميل r08', 'r08-a@example.com', '+970599000808');
INSERT INTO bookings (service_id, customer_id, preferred_contact, details, price_amount, currency_code,
                      access_token_hash, consent_at)
SELECT s.id, c.id, 'email', 'r08 details', 12345, 'USD', encode(sha256('r08-booking-a'::bytea), 'hex'), now()
FROM services s, customers c WHERE s.slug = 'r08-service-a' AND c.email = 'r08-a@example.com';
SELECT pg_temp.expect_fail('bookings: preferred contact is phone, whatsapp or email',
  $$INSERT INTO bookings (service_id, customer_id, preferred_contact, details, access_token_hash, consent_at)
    SELECT s.id, c.id, 'fax', 'r08', encode(sha256('r08-booking-b'::bytea), 'hex'), now()
    FROM services s, customers c WHERE s.slug = 'r08-service-a' AND c.email = 'r08-a@example.com'$$, 'bookings_preferred_contact_check');
SELECT pg_temp.expect_fail('bookings: a price snapshot needs its currency',
  $$INSERT INTO bookings (service_id, customer_id, preferred_contact, details, price_amount, access_token_hash, consent_at)
    SELECT s.id, c.id, 'phone', 'r08', 12345, encode(sha256('r08-booking-c'::bytea), 'hex'), now()
    FROM services s, customers c WHERE s.slug = 'r08-service-a' AND c.email = 'r08-a@example.com'$$, 'bookings_check');
SELECT pg_temp.expect_fail('bookings: reference sequence must be positive',
  $$UPDATE bookings SET reference_seq = 0 WHERE details = 'r08 details'$$, 'bookings_reference_seq_check');

-- ---------------------------------------------------------------- payments
INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status,
                      idempotency_key, paid_at)
SELECT id, 'fake', 1, 'R08-ORD-1', 12345, 'USD', 'captured', 'r08-bk-a1', now() FROM bookings WHERE details = 'r08 details';
SELECT pg_temp.expect_fail('payments: attempt number must be positive',
  $$INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, idempotency_key)
    SELECT id, 'fake', 0, 'R08-ORD-0', 12345, 'USD', 'r08-bk-a0' FROM bookings WHERE details = 'r08 details'$$, 'payments_attempt_no_check');
SELECT pg_temp.expect_fail('payments: amount must be above zero',
  $$INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, idempotency_key)
    SELECT id, 'fake', 2, 'R08-ORD-2', 0, 'USD', 'r08-bk-a2' FROM bookings WHERE details = 'r08 details'$$, 'payments_amount_check');
SELECT pg_temp.expect_fail('payments: unknown status',
  $$INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status, idempotency_key)
    SELECT id, 'fake', 2, 'R08-ORD-2', 12345, 'USD', 'settled', 'r08-bk-a2' FROM bookings WHERE details = 'r08 details'$$, 'payments_status_check');
SELECT pg_temp.expect_fail('payments: a failed attempt needs a reason',
  $$INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status, idempotency_key)
    SELECT id, 'fake', 2, 'R08-ORD-2', 12345, 'USD', 'failed', 'r08-bk-a2' FROM bookings WHERE details = 'r08 details'$$, 'payments_check1');
INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status,
                      idempotency_key, failure_reason)
SELECT id, 'fake', 2, 'R08-ORD-2', 12345, 'USD', 'failed', 'r08-bk-a2', 'r08 declined' FROM bookings WHERE details = 'r08 details';
SELECT pg_temp.expect_fail('payment_gateways: code is lower-case letters, digits and underscores',
  $$INSERT INTO payment_gateways (code, name_ar) VALUES ('R08-Gateway', 'r08')$$, 'payment_gateways_code_check');

-- ---------------------------------------------------------------- refunds
SELECT pg_temp.expect_fail('refunds: amount must be above zero',
  $$INSERT INTO refunds (payment_id, amount) SELECT id, 0 FROM payments WHERE gateway_order_id = 'R08-ORD-1'$$, 'refunds_amount_check');
SELECT pg_temp.expect_fail('refunds: unknown status',
  $$INSERT INTO refunds (payment_id, amount, status) SELECT id, 1000, 'done' FROM payments WHERE gateway_order_id = 'R08-ORD-1'$$, 'refunds_status_check');
INSERT INTO refunds (payment_id, amount, status) SELECT id, 1000, 'succeeded' FROM payments WHERE gateway_order_id = 'R08-ORD-1';

-- ---------------------------------------------------------------- invoice lines
INSERT INTO invoices (payment_id, billed_name, billed_email, currency_code)
SELECT id, 'عميل r08', 'r08-a@example.com', 'USD' FROM payments WHERE gateway_order_id = 'R08-ORD-1';
INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount)
SELECT i.id, 1, 'r08 line', 1, 12345 FROM invoices i JOIN payments p ON p.id = i.payment_id WHERE p.gateway_order_id = 'R08-ORD-1';
-- The number trigger always writes number_seq from next_document_number() (1 or more), so the CHECK is the net under
-- it: with the trigger off, inside the refused statement (rolled back with it), a zero is still refused.
SELECT pg_temp.expect_fail('invoices: a number below 1 is refused even without the number trigger',
  $sql$DO $do$ BEGIN
      ALTER TABLE invoices DISABLE TRIGGER invoices_number;
      INSERT INTO invoices (payment_id, billed_name, billed_email, currency_code, number_year, number_seq)
      SELECT id, 'عميل r08', 'r08-a@example.com', 'USD', 2026, 0 FROM payments WHERE gateway_order_id = 'R08-ORD-1';
    END $do$$sql$, 'invoices_number_seq_check');
SELECT pg_temp.expect_fail('invoice_lines: line number must be positive',
  $$INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount)
    SELECT i.id, 0, 'r08 line', 1, 12345 FROM invoices i JOIN payments p ON p.id = i.payment_id WHERE p.gateway_order_id = 'R08-ORD-1'$$, 'invoice_lines_line_no_check');
SELECT pg_temp.expect_fail('invoice_lines: quantity must be above zero',
  $$INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount)
    SELECT i.id, 2, 'r08 line', 0, 12345 FROM invoices i JOIN payments p ON p.id = i.payment_id WHERE p.gateway_order_id = 'R08-ORD-1'$$, 'invoice_lines_quantity_check');

-- ---------------------------------------------------------------- finite numbers (NaN)
-- NaN counts as larger than any number, so "> 0" alone let a NaN quantity through, and the invoice's total with it.
SELECT pg_temp.expect_fail('invoice_lines: a NaN quantity is refused',
  $$INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount)
    SELECT i.id, 2, 'r08 nan', 'NaN', 1000 FROM invoices i JOIN payments p ON p.id = i.payment_id WHERE p.gateway_order_id = 'R08-ORD-1'$$, 'invoice_lines_quantity_check');
