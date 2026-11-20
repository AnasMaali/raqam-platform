-- =====================================================================
--  A copy of the QA database given the commerce rows the demo does not make (the booking and payment systems are
--  dormant in V1, so raqam:demo leaves them empty): customers, bookings walked through their statuses, attachments,
--  payments, refunds and invoices with their lines. Every row goes through the database's own rules (history,
--  references, numbers, the refund and attachment limits). All amounts are made up.
--
--    createdb -T raqam_qa raqam_qa_<copy>
--    psql -d raqam_qa_<copy> -v ON_ERROR_STOP=1 -f backend/scripts/qa-commerce-sample.sql
--
--  Only a copy: it stops on any database whose name does not start with raqam_qa_.
-- =====================================================================
DO $$ BEGIN
  IF current_database() NOT LIKE 'raqam\_qa\_%' THEN
    RAISE EXCEPTION 'qa-commerce-sample: only on a copy of raqam_qa (raqam_qa_*), not %', current_database();
  END IF;
END $$;

SELECT setseed(0.48);

INSERT INTO payment_gateways (code, name_ar) VALUES ('fake', 'بوابة تجريبية (للاختبار فقط)') ON CONFLICT DO NOTHING;

INSERT INTO customers (name, email, phone)
SELECT 'زبون ' || n, format('qa-customer-%s@example.com', n), '+97059' || lpad((1000000 + n)::text, 7, '0')
FROM generate_series(1, 200) n;

-- 300 bookings over the services, each walked a few steps along the allowed transitions.
INSERT INTO bookings (service_id, customer_id, preferred_contact, details, price_amount, currency_code, access_token_hash, consent_at, created_at)
SELECT s.id, c.id, (ARRAY['whatsapp', 'phone', 'email'])[1 + n % 3], 'طلب تجريبي رقم ' || n, 10000 + (n % 7) * 1111, 'USD',
       encode(sha256(('qa-booking-' || n)::bytea), 'hex'), now() - make_interval(days => n % 90), now() - make_interval(days => n % 90)
FROM generate_series(1, 300) n
JOIN LATERAL (SELECT id FROM services ORDER BY id OFFSET n % (SELECT count(*) FROM services) LIMIT 1) s ON true
JOIN customers c ON c.email = format('qa-customer-%s@example.com', 1 + n % 200);

DO $$
DECLARE
  b record;
  v_next varchar(16);
  v_steps int;
BEGIN
  FOR b IN SELECT id FROM bookings WHERE details LIKE 'طلب تجريبي رقم %' ORDER BY id LOOP
    v_steps := floor(random() * 6)::int;
    FOR i IN 1 .. v_steps LOOP
      SELECT t.to_code INTO v_next FROM booking_status_transitions t JOIN bookings k ON k.status_code = t.from_code
      WHERE k.id = b.id ORDER BY random() LIMIT 1;
      EXIT WHEN v_next IS NULL;
      INSERT INTO booking_status_history (booking_id, status_code, note) VALUES (b.id, v_next, 'خطوة تجريبية');
    END LOOP;
  END LOOP;
END $$;

-- Up to 3 private files on a third of the bookings.
INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, visibility)
SELECT format('private/qa-attachment-%s.pdf', n), format('ملف-%s.pdf', n), 'application/pdf', 20000 + n,
       encode(sha256(('qa-attachment-' || n)::bytea), 'hex'), 'private'
FROM generate_series(1, 300) n;
INSERT INTO booking_attachments (booking_id, media_id)
SELECT b.id, m.id
FROM (SELECT id, row_number() OVER (ORDER BY id) AS r FROM bookings WHERE details LIKE 'طلب تجريبي رقم %') b
JOIN media_assets m ON m.path IN (format('private/qa-attachment-%s.pdf', b.r), format('private/qa-attachment-%s.pdf', b.r + 100),
                                  format('private/qa-attachment-%s.pdf', b.r + 200))
WHERE b.r % 3 = 0 AND b.r <= 100;

-- A captured payment for every booking that reached "paid", a failed attempt before some of them.
INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status, idempotency_key, failure_reason)
SELECT DISTINCT h.booking_id, 'fake', 1, 'QA-ORD-' || h.booking_id || '-1', b.price_amount, 'USD', 'failed', 'qa-key-' || h.booking_id || '-1', 'رفض تجريبي'
FROM booking_status_history h JOIN bookings b ON b.id = h.booking_id
WHERE h.status_code = 'paid' AND h.booking_id % 4 = 0;
INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status, idempotency_key, paid_at)
SELECT DISTINCT h.booking_id, 'fake', 2, 'QA-ORD-' || h.booking_id || '-2', b.price_amount, 'USD', 'captured', 'qa-key-' || h.booking_id || '-2', h.created_at
FROM booking_status_history h JOIN bookings b ON b.id = h.booking_id
WHERE h.status_code = 'paid';

-- Refunds on a fifth of them: one part, sometimes a second, one that failed (failed ones do not count).
INSERT INTO refunds (payment_id, amount, status) SELECT id, amount / 4, 'succeeded' FROM payments WHERE status = 'captured' AND id % 5 = 0;
INSERT INTO refunds (payment_id, amount, status) SELECT id, amount / 2, 'succeeded' FROM payments WHERE status = 'captured' AND id % 10 = 0;
INSERT INTO refunds (payment_id, amount, status) SELECT id, amount, 'failed' FROM payments WHERE status = 'captured' AND id % 5 = 0;

-- An invoice for every captured payment, one to three lines, VAT on half of them.
INSERT INTO invoices (payment_id, billed_name, billed_email, currency_code, tax_rate_bp)
SELECT p.id, c.name, c.email, 'USD', CASE WHEN p.id % 2 = 0 THEN 1600 END
FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN customers c ON c.id = b.customer_id
WHERE p.status = 'captured';
INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount)
SELECT i.id, l, 'بند تجريبي ' || l, (ARRAY[1, 2.5, 0.333])[l], 1234 + 37 * l + i.id % 11
FROM invoices i CROSS JOIN generate_series(1, 3) l
WHERE l <= 1 + i.id % 3;

SELECT (SELECT count(*) FROM bookings) AS bookings, (SELECT count(*) FROM booking_status_history) AS history,
       (SELECT count(*) FROM booking_attachments) AS attachments, (SELECT count(*) FROM payments) AS payments,
       (SELECT count(*) FROM refunds) AS refunds, (SELECT count(*) FROM invoices) AS invoices,
       (SELECT count(*) FROM invoice_lines) AS invoice_lines;
