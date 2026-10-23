<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/09-engagement.sql, ported from database/tests/schema_tests.sql.

function insertFaq(): int
{
    return DB::table('faqs')->insertGetId(['question' => 'كيف أعلن؟', 'answer' => 'تواصل معنا']);
}

function emailTemplate(): string
{
    ensure('email_templates', ['key' => 'admin_login_code', 'name_ar' => 'رمز الدخول', 'audience' => 'admin', 'subject' => 'رمز الدخول', 'body_html' => '<p>{{code}}</p>']);

    return 'admin_login_code';
}

function insertConversation(): int
{
    return DB::table('bot_conversations')->insertGetId(['session_hash' => str_repeat('2', 64)]);
}

describe('chatbot', function () {
    test('an FAQ-backed entry does not repeat the FAQ text', function () {
        expect(rejected(fn () => DB::table('bot_entries')->insert(['faq_id' => insertFaq(), 'question' => 'q', 'answer' => 'a'])))
            ->toThrow(QueryException::class, '"bot_entries_check"');
    });

    test('an entry is either an FAQ or its own question and answer', function () {
        DB::table('bot_entries')->insert(['faq_id' => insertFaq()]);
        DB::table('bot_entries')->insert(['question' => 'متى تصدر النشرة؟', 'answer' => 'كل سبت']);

        expect(rejected(fn () => DB::table('bot_entries')->insert(['question' => 'سؤال بلا جواب'])))
            ->toThrow(QueryException::class, '"bot_entries_check"');
    });

    test('an entry action points at its target', function (string $action, string $constraint) {
        expect(rejected(fn () => DB::table('bot_entries')->insert(['question' => 'q', 'answer' => 'a', 'action_type' => $action])))
            ->toThrow(QueryException::class, "\"{$constraint}\"");
    })->with([
        'service' => ['service', 'bot_entries_check1'],
        'page' => ['page', 'bot_entries_check2'],
        'url' => ['url', 'bot_entries_check3'],
    ]);

    test('a service action accepts its service', function () {
        DB::table('bot_entries')->insert(['question' => 'كم سعر الحلقة؟', 'answer' => 'حسب الخدمة', 'action_type' => 'service', 'action_service_id' => insertService()]);

        expect(DB::table('bot_entries')->count())->toBe(1);
    });

    test('a quick reply points at its target', function () {
        expect(rejected(fn () => DB::table('bot_quick_replies')->insert(['label_ar' => 'الأسعار', 'action_type' => 'entry'])))
            ->toThrow(QueryException::class, '"bot_quick_replies_check"');
    });

    test('a visitor message is at most 500 characters', function () {
        $conversation = insertConversation();

        expect(rejected(fn () => DB::table('bot_messages')->insert(['conversation_id' => $conversation, 'role' => 'user', 'content' => str_repeat('ا', 501)])))
            ->toThrow(QueryException::class, '"bot_messages_check"');
    });

    test('a bot reply may be longer than 500 characters', function () {
        DB::table('bot_messages')->insert(['conversation_id' => insertConversation(), 'role' => 'bot', 'content' => str_repeat('ا', 800)]);

        expect(DB::table('bot_messages')->count())->toBe(1);
    });

    test('only visitor messages carry a match score', function () {
        expect(rejected(fn () => DB::table('bot_messages')->insert(['conversation_id' => insertConversation(), 'role' => 'bot', 'content' => 'أهلاً', 'match_score' => 0.9])))
            ->toThrow(QueryException::class, '"bot_messages_check1"');
    });
});

describe('newsletter', function () {
    test('an issue week starts on Saturday', function () {
        expect(rejected(fn () => DB::table('newsletter_issues')->insert(['week_start' => '2026-10-12', 'subject' => 'x'])))
            ->toThrow(QueryException::class, '"newsletter_issues_week_start_check"');

        DB::table('newsletter_issues')->insert(['week_start' => '2026-10-17', 'subject' => 'نشرة الأسبوع']);
    });

    test('an issue handed to Brevo has its campaign id', function () {
        expect(rejected(fn () => DB::table('newsletter_issues')->insert(['week_start' => '2026-10-17', 'subject' => 'x', 'status' => 'sent_to_brevo'])))
            ->toThrow(QueryException::class, '"newsletter_issues_check"');
    });

    test('a sent issue records when', function () {
        expect(rejected(fn () => DB::table('newsletter_issues')->insert(['week_start' => '2026-10-17', 'subject' => 'x', 'status' => 'sent', 'brevo_campaign_id' => '42'])))
            ->toThrow(QueryException::class, '"newsletter_issues_check1"');
    });

    test('a post in an issue cannot be deleted', function () {
        $post = insertPost();
        $issue = DB::table('newsletter_issues')->insertGetId(['week_start' => '2026-10-17', 'subject' => 'x']);
        DB::table('newsletter_issue_posts')->insert(['issue_id' => $issue, 'post_id' => $post]);

        expect(rejected(fn () => DB::table('posts')->delete($post)))
            ->toThrow(QueryException::class, '"newsletter_issue_posts_post_id_fkey"');
    });

    test('an active subscriber has confirmed', function () {
        expect(rejected(fn () => DB::table('subscribers')->insert(['email' => 'n@example.com', 'status' => 'active', 'source_placement' => 'footer'])))
            ->toThrow(QueryException::class, '"subscribers_check"');
    });

    test('an unsubscribed address records when', function () {
        expect(rejected(fn () => DB::table('subscribers')->insert(['email' => 'n@example.com', 'status' => 'unsubscribed', 'source_placement' => 'footer'])))
            ->toThrow(QueryException::class, '"subscribers_check1"');
    });

    test('a subscriber records which form the consent came from (19.3, 19.9)', function () {
        expect(rejected(fn () => DB::table('subscribers')->insert(['email' => 'n@example.com', 'status' => 'active', 'confirmed_at' => now()])))
            ->toThrow(QueryException::class, 'null value in column "source_placement"');
        expect(rejected(fn () => DB::table('subscribers')->insert(['email' => 'n@example.com', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'popup'])))
            ->toThrow(QueryException::class, '"subscribers_source_placement_check"');

        foreach (['footer', 'post_end', 'newsletter_page'] as $i => $placement) {
            DB::table('subscribers')->insert(['email' => "n{$i}@example.com", 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => $placement]);
        }
        expect(DB::table('subscribers')->count())->toBe(3);
    });
});

describe('messages', function () {
    test('a failed e-mail records its error', function () {
        expect(rejected(fn () => DB::table('email_log')->insert(['template_key' => emailTemplate(), 'recipient' => 'admin@raqam.ps', 'status' => 'failed'])))
            ->toThrow(QueryException::class, '"email_log_check1"');
    });

    test('an e-mail is retried at most three times', function () {
        expect(rejected(fn () => DB::table('email_log')->insert(['template_key' => emailTemplate(), 'recipient' => 'admin@raqam.ps', 'attempts' => 4])))
            ->toThrow(QueryException::class, '"email_log_attempts_check"');
    });

    test('a contact message is between 2 and 5000 characters', function (string $message) {
        expect(rejected(fn () => DB::table('contact_messages')->insert(['name' => 'سامي', 'email' => 'sami@example.com', 'subject' => 'سؤال', 'message' => $message])))
            ->toThrow(QueryException::class, '"contact_messages_message_check"');
    })->with(['too short' => ['ا'], 'too long' => [str_repeat('ا', 5001)]]);
});
