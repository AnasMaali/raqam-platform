-- =====================================================================
--  Rules of 09-engagement.sql: chatbot, newsletter, e-mail, admin
--  notifications, contact form. Each "must fail" case breaks one CHECK
--  and is wrapped in expect_fail(); rows the tests need are plain
--  statements. Unique values carry the prefix r09.
-- =====================================================================

-- ---------------------------------------------------------------- bot entries
INSERT INTO bot_entries (question, answer, action_type, action_url)
VALUES ('r09 سؤال', 'r09 جواب', 'url', 'https://example.com/r09');
SELECT pg_temp.expect_eq('bot_entries: an own Q/A with a url action is accepted',
  (SELECT count(*)::text FROM bot_entries WHERE question = 'r09 سؤال'), '1');
SELECT pg_temp.expect_fail('bot_entries: unknown action type refused',
  $$INSERT INTO bot_entries (question, answer, action_type) VALUES ('r09 q', 'r09 a', 'email')$$, 'bot_entries_action_type_check');
SELECT pg_temp.expect_fail('bot_entries: page action without a page refused',
  $$INSERT INTO bot_entries (question, answer, action_type) VALUES ('r09 q', 'r09 a', 'page')$$, 'bot_entries_check2');
SELECT pg_temp.expect_fail('bot_entries: url action without a url refused',
  $$INSERT INTO bot_entries (question, answer, action_type) VALUES ('r09 q', 'r09 a', 'url')$$, 'bot_entries_check3');
SELECT pg_temp.expect_fail('bot_entries: a url on an entry whose action is not url refused',
  $$UPDATE bot_entries SET action_type = 'none' WHERE question = 'r09 سؤال'$$, 'bot_entries_check3');

-- ---------------------------------------------------------------- bot quick replies
INSERT INTO bot_quick_replies (label_ar, action_type, target_url)
VALUES ('r09 زر', 'url', 'https://example.com/r09-button');
SELECT pg_temp.expect_eq('bot_quick_replies: a url button is accepted',
  (SELECT count(*)::text FROM bot_quick_replies WHERE label_ar = 'r09 زر'), '1');
SELECT pg_temp.expect_fail('bot_quick_replies: a button that does nothing refused',
  $$INSERT INTO bot_quick_replies (label_ar, action_type) VALUES ('r09 x', 'none')$$, 'bot_quick_replies_action_type_check');
SELECT pg_temp.expect_fail('bot_quick_replies: entry button without an entry refused',
  $$INSERT INTO bot_quick_replies (label_ar, action_type) VALUES ('r09 x', 'entry')$$, 'bot_quick_replies_check');
SELECT pg_temp.expect_fail('bot_quick_replies: service button without a service refused',
  $$INSERT INTO bot_quick_replies (label_ar, action_type) VALUES ('r09 x', 'service')$$, 'bot_quick_replies_check1');
SELECT pg_temp.expect_fail('bot_quick_replies: page button without a page refused',
  $$INSERT INTO bot_quick_replies (label_ar, action_type) VALUES ('r09 x', 'page')$$, 'bot_quick_replies_check2');
SELECT pg_temp.expect_fail('bot_quick_replies: url button without a url refused',
  $$INSERT INTO bot_quick_replies (label_ar, action_type) VALUES ('r09 x', 'url')$$, 'bot_quick_replies_check3');

-- ---------------------------------------------------------------- bot messages
INSERT INTO bot_conversations (session_hash, entry_path) VALUES (repeat('9', 64), '/r09-chat');
INSERT INTO bot_messages (conversation_id, role, content, match_score)
SELECT id, 'user', 'r09 سؤال الزائر', 0.75 FROM bot_conversations WHERE entry_path = '/r09-chat';
SELECT pg_temp.expect_eq('bot_messages: a user message with a score is accepted',
  (SELECT count(*)::text FROM bot_messages m JOIN bot_conversations c ON c.id = m.conversation_id
    WHERE c.entry_path = '/r09-chat'), '1');
SELECT pg_temp.expect_fail('bot_messages: unknown role refused',
  $$INSERT INTO bot_messages (conversation_id, role, content)
    SELECT id, 'admn', 'r09' FROM bot_conversations WHERE entry_path = '/r09-chat'$$, 'bot_messages_role_check');
SELECT pg_temp.expect_fail('bot_messages: match score above 1 refused',
  $$INSERT INTO bot_messages (conversation_id, role, content, match_score)
    SELECT id, 'user', 'r09', 1.5 FROM bot_conversations WHERE entry_path = '/r09-chat'$$, 'bot_messages_match_score_check');
SELECT pg_temp.expect_fail('bot_messages: negative match score refused',
  $$INSERT INTO bot_messages (conversation_id, role, content, match_score)
    SELECT id, 'user', 'r09', -0.1 FROM bot_conversations WHERE entry_path = '/r09-chat'$$, 'bot_messages_match_score_check');
SELECT pg_temp.expect_fail('bot_messages: a bot reply cannot carry a match score',
  $$INSERT INTO bot_messages (conversation_id, role, content, match_score)
    SELECT id, 'bot', 'r09', 0.5 FROM bot_conversations WHERE entry_path = '/r09-chat'$$, 'bot_messages_check1');
SELECT pg_temp.expect_fail('bot_messages: a bot reply cannot be marked reviewed',
  $$INSERT INTO bot_messages (conversation_id, role, content, reviewed_at)
    SELECT id, 'bot', 'r09', now() FROM bot_conversations WHERE entry_path = '/r09-chat'$$, 'bot_messages_check1');

-- ---------------------------------------------------------------- subscribers
INSERT INTO subscribers (email, source_placement) VALUES ('r09-sub@example.com', 'footer');
SELECT pg_temp.expect_fail('subscribers: unknown status refused',
  $$INSERT INTO subscribers (email, source_placement, status) VALUES ('r09-x@example.com', 'footer', 'deleted')$$, 'subscribers_status_check');
SELECT pg_temp.expect_fail('subscribers: unsubscribed without the time refused',
  $$UPDATE subscribers SET status = 'unsubscribed' WHERE email = 'r09-sub@example.com'$$, 'subscribers_check1');
UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = now() WHERE email = 'r09-sub@example.com';
SELECT pg_temp.expect_eq('subscribers: unsubscribed with the time is accepted',
  (SELECT status FROM subscribers WHERE email = 'r09-sub@example.com'), 'unsubscribed');

-- ---------------------------------------------------------------- newsletter issues
-- 2001-01-06 is a Saturday.
INSERT INTO newsletter_issues (week_start, subject) VALUES ('2001-01-06', 'r09 العدد');
SELECT pg_temp.expect_fail('newsletter_issues: unknown status refused',
  $$UPDATE newsletter_issues SET status = 'queued', brevo_campaign_id = 'r09' WHERE week_start = '2001-01-06'$$, 'newsletter_issues_status_check');
SELECT pg_temp.expect_fail('newsletter_issues: handed to Brevo without a campaign id refused',
  $$UPDATE newsletter_issues SET status = 'sent_to_brevo' WHERE week_start = '2001-01-06'$$, 'newsletter_issues_check');
SELECT pg_temp.expect_fail('newsletter_issues: sent without the time refused',
  $$UPDATE newsletter_issues SET status = 'sent', brevo_campaign_id = 'r09-c' WHERE week_start = '2001-01-06'$$, 'newsletter_issues_check1');
UPDATE newsletter_issues SET status = 'sent', brevo_campaign_id = 'r09-c', sent_at = now() WHERE week_start = '2001-01-06';
SELECT pg_temp.expect_eq('newsletter_issues: sent with campaign id and time is accepted',
  (SELECT status FROM newsletter_issues WHERE week_start = '2001-01-06'), 'sent');

-- ---------------------------------------------------------------- email templates
-- Template keys allow only a-z and "_", so the prefix is spelled out.
INSERT INTO email_templates (key, name_ar, audience, subject, body_html)
VALUES ('rules_nine_notice', 'r09 قالب', 'visitor', 'r09', '<p>r09</p>');
SELECT pg_temp.expect_fail('email_templates: key with digits or capitals refused',
  $$INSERT INTO email_templates (key, name_ar, audience, subject, body_html) VALUES ('R09_notice', 'r09', 'visitor', 's', 'b')$$, 'email_templates_key_check');
SELECT pg_temp.expect_fail('email_templates: unknown audience refused',
  $$INSERT INTO email_templates (key, name_ar, audience, subject, body_html) VALUES ('rules_nine_staff', 'r09', 'staff', 's', 'b')$$, 'email_templates_audience_check');

-- ---------------------------------------------------------------- email log
INSERT INTO email_log (template_key, recipient) VALUES ('rules_nine_notice', 'r09-log@example.com');
SELECT pg_temp.expect_fail('email_log: unknown status refused',
  $$UPDATE email_log SET status = 'bounce' WHERE recipient = 'r09-log@example.com'$$, 'email_log_status_check');
SELECT pg_temp.expect_fail('email_log: more than 3 attempts refused',
  $$UPDATE email_log SET attempts = 4 WHERE recipient = 'r09-log@example.com'$$, 'email_log_attempts_check');
SELECT pg_temp.expect_fail('email_log: negative attempts refused',
  $$UPDATE email_log SET attempts = -1 WHERE recipient = 'r09-log@example.com'$$, 'email_log_attempts_check');
SELECT pg_temp.expect_fail('email_log: sent without the time refused',
  $$UPDATE email_log SET status = 'sent' WHERE recipient = 'r09-log@example.com'$$, 'email_log_check');
SELECT pg_temp.expect_fail('email_log: failed without an error refused',
  $$UPDATE email_log SET status = 'failed', attempts = 3 WHERE recipient = 'r09-log@example.com'$$, 'email_log_check1');
UPDATE email_log SET status = 'failed', attempts = 3, error = 'r09 timeout' WHERE recipient = 'r09-log@example.com';
SELECT pg_temp.expect_eq('email_log: failed with an error is accepted',
  (SELECT status FROM email_log WHERE recipient = 'r09-log@example.com'), 'failed');

-- ---------------------------------------------------------------- admin notifications
INSERT INTO users (user_type, name, email, password_hash) VALUES ('staff', 'R09 Admin', 'r09-admin@example.com', 'x');
INSERT INTO admin_notifications (user_id, category, title)
SELECT id, 'system', 'r09 تنبيه' FROM users WHERE email = 'r09-admin@example.com';
SELECT pg_temp.expect_fail('admin_notifications: unknown category refused',
  $$INSERT INTO admin_notifications (user_id, category, title)
    SELECT id, 'billing', 'r09' FROM users WHERE email = 'r09-admin@example.com'$$, 'admin_notifications_category_check');

-- ---------------------------------------------------------------- contact messages
INSERT INTO contact_messages (name, email, subject, message) VALUES ('r09', 'r09-contact@example.com', 'r09', 'مرحبا');
SELECT pg_temp.expect_fail('contact_messages: one-letter message refused',
  $$INSERT INTO contact_messages (name, email, subject, message) VALUES ('r09', 'r09-c@example.com', 's', 'x')$$, 'contact_messages_message_check');
SELECT pg_temp.expect_fail('contact_messages: message over 5000 characters refused',
  $$INSERT INTO contact_messages (name, email, subject, message) VALUES ('r09', 'r09-c@example.com', 's', repeat('م', 5001))$$, 'contact_messages_message_check');
SELECT pg_temp.expect_fail('contact_messages: unknown status refused',
  $$UPDATE contact_messages SET status = 'spam' WHERE email = 'r09-contact@example.com'$$, 'contact_messages_status_check');
