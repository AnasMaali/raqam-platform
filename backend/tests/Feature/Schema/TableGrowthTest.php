<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

uses(RefreshDatabase::class);

/*
 * Every table, by how it grows (docs/qa/database.md, "Size and growth"):
 *   - cleaned: the platform writes it by itself, and a scheduled command (or a cap) keeps it from growing for ever;
 *   - kept: the platform writes it by itself and keeps every row, for the reason written here;
 *   - people's: it grows only with what editors, admins, customers or the reference data add.
 * A new table must be put in one of them, so nobody adds a log that is never cleaned without saying so.
 */

/** table => [the command that cleans it (or null for a cap in the code), the file that does it, how] */
const CLEANED_TABLES = [
    'audit_log' => ['audit:prune', 'app/Console/PruneAuditLogCommand.php', 'older than 2 years'],
    'audit_log_changes' => ['audit:prune', 'app/Console/PruneAuditLogCommand.php', 'with its audit row'],
    'login_attempts' => ['audit:prune', 'app/Console/PruneAuditLogCommand.php', 'with the audit log\'s age'],
    'bot_conversations' => ['retention:prune', 'app/Console/PruneRetainedDataCommand.php', 'chats after 180 days'],
    'bot_messages' => ['retention:prune', 'app/Console/PruneRetainedDataCommand.php', 'with their conversation'],
    'contact_messages' => ['retention:prune', 'app/Console/PruneRetainedDataCommand.php', 'after 365 days'],
    'verification_codes' => ['retention:prune', 'app/Console/PruneRetainedDataCommand.php', 'spent or expired, after a day'],
    'email_log' => ['emails:prune', 'app/Console/PruneEmailLogCommand.php', 'after its retention days'],
    'not_found_paths' => ['stats:prune', 'app/Console/StatsPruneCommand.php', 'after 90 days'],
    'user_devices' => ['devices:prune', 'app/Auth/KnownDevices.php', 'unseen for 6 months'],
    'posts' => ['posts:purge-trashed', 'app/Console/PurgeTrashedPostsCommand.php', 'in the bin for 90 days'],
    'failed_jobs' => ['queue:prune-failed', null, 'Laravel\'s own, by age'],
    'post_revisions' => [null, 'app/Content/PostSaver.php', 'the last MAX_REVISIONS (100) of each post'],
    'weather_observations' => ['retention:prune', 'app/Console/PruneRetainedDataCommand.php', 'after 30 days: only today\'s are shown (decision QA-7)'],
    'admin_notifications' => ['retention:prune', 'app/Console/PruneRetainedDataCommand.php', '90 days after they were read; unread ones never (decision QA-7)'],
];

/** table => why every row is kept */
const KEPT_TABLES = [
    'ad_stats_daily' => 'one row a day per campaign and place: the advertiser\'s report covers any period',
    'device_stats_daily' => 'one row a day per device type: the statistics cover any period',
    'event_stats_daily' => 'one row a day per event type and item: the statistics cover any period',
    'post_views_daily' => 'one row a day per post read: the statistics and the totals cover any period',
    'search_stats_daily' => 'one row a day per term searched, at most 2000 new terms a day',
    'search_terms' => 'the words searched, at most 2000 new a day, named by the search statistics',
    'service_contact_clicks_daily' => 'one row a day per service and channel: the statistics cover any period',
    'site_stats_daily' => 'one row a day',
    'traffic_sources_daily' => 'one row a day per source',
    'video_plays_daily' => 'one row a day per video played',
    'exchange_rates' => 'one rate a working day per currency: the history and the change are read from it',
    'payment_events' => 'the bank\'s messages of real payments stay with the payment (forged ones go after 90 days, retention:prune)',
    'payments' => 'money records',
    'refunds' => 'money records',
    'invoices' => 'legal records, unchangeable',
    'invoice_lines' => 'legal records, unchangeable',
    'bookings' => 'the customer\'s requests and their history',
    'booking_status_history' => 'a booking\'s history',
    'booking_attachments' => 'at most 3 per booking',
    'customers' => 'one per e-mail',
    'subscribers' => 'one per e-mail, until they leave',
    'newsletter_issues' => 'one a week',
    'newsletter_issue_posts' => 'the posts of one issue',
    'provider_health' => 'one row per outside source',
    'document_counters' => 'one row per document type and year',
    'weather_forecasts' => 'replaced at each fetch: a few days per city',
    'job_batches' => 'Laravel\'s own; the platform runs no batches',
];

test('every table is cleaned, kept for a written reason, or grows only with what people add', function () {
    $tables = collect(DB::select("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'r' ORDER BY 1"))
        ->pluck('relname')->reject(fn ($t) => $t === 'migrations')->values()->all();
    $peoples = array_values(array_diff($tables, array_keys(CLEANED_TABLES), array_keys(KEPT_TABLES)));

    $listed = PEOPLES_TABLES;
    sort($listed);
    sort($peoples);

    expect(array_values(array_intersect(array_keys(CLEANED_TABLES), array_keys(KEPT_TABLES))))->toBe([], 'a table in two lists')
        ->and(array_values(array_diff(array_keys(CLEANED_TABLES), $tables)))->toBe([], 'a cleaned table that does not exist')
        ->and(array_values(array_diff(array_keys(KEPT_TABLES), $tables)))->toBe([], 'a kept table that does not exist')
        ->and(array_values(array_diff($listed, $tables)))->toBe([], 'a people\'s table that does not exist')
        ->and($peoples)->toBe($listed);
});

/** Written by editors, admins, customers or the reference seed: they grow only as people add to them. */
const PEOPLES_TABLES = [
    'ad_campaign_placements', 'ad_campaigns', 'ad_creatives', 'ad_placements', 'advertisers', 'author_links', 'authors',
    'awareness_cards', 'awareness_types', 'bot_entries', 'bot_entry_keywords', 'bot_entry_quick_replies', 'bot_entry_variants',
    'bot_quick_replies', 'booking_status_transitions', 'booking_statuses', 'categories', 'character_poses', 'characters', 'cities',
    'currencies', 'currency_pegs', 'data_providers', 'email_templates', 'eras', 'event_types', 'faqs', 'feature_flags',
    'followed_categories', 'followed_sections', 'guest_links', 'guest_roles', 'guests', 'history_entries', 'home_blocks', 'house_ads',
    'infographics', 'initiatives', 'media_assets', 'media_variants', 'menu_items', 'menus', 'organizations', 'pages',
    'payment_gateways', 'permissions', 'playlist_videos', 'playlists', 'podcast_episodes', 'podcast_seasons', 'pose_types',
    'post_authors', 'post_body_media', 'post_body_videos', 'post_gallery_items', 'post_guests', 'post_kinds', 'post_related',
    'post_tags', 'products', 'rate_board_currencies', 'rate_overrides', 'redirects', 'role_permissions', 'roles', 'roundtable_sessions',
    'saved_posts', 'sections', 'sectors', 'seo_meta', 'service_deliverables', 'service_groups', 'services', 'settings',
    'site_social_links', 'social_platforms', 'success_stories', 'success_story_figures', 'synonym_groups', 'synonym_terms', 'tags',
    'traffic_sources', 'user_roles', 'users', 'video_chapters', 'videos', 'weather_conditions', 'weather_locations', 'youtube_videos',
];

test('every cleaning is scheduled, and the file named does it', function () {
    // The schedule is written in routes/console.php, which a test does not load by itself.
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command ?? '')->implode("\n");

    foreach (CLEANED_TABLES as $table => [$command, $file, $how]) {
        if ($command !== null) {
            expect(str_contains($scheduled, $command))->toBeTrue("{$table}: {$command} is not scheduled");
        }
        if ($file !== null) {
            $source = file_get_contents(base_path($file));
            // The query on the table itself (a name in a config key or a comment does not count).
            $named = match ($table) {
                'audit_log_changes' => "table('audit_log')",          // cascades with its parent row
                'bot_messages' => "table('bot_conversations')",       // the same
                'posts' => 'onlyTrashed',
                'post_revisions' => 'MAX_REVISIONS',
                default => "table('{$table}')",
            };
            expect(str_contains($source, $named))->toBeTrue("{$table}: {$file} does not clean it ({$how})");
        }
    }
});

test('a cascade named above really takes the child rows with their parent', function () {
    $cascades = collect(DB::select(<<<'SQL'
        SELECT conrelid::regclass::text AS child, confrelid::regclass::text AS parent FROM pg_constraint
        WHERE contype = 'f' AND confdeltype = 'c' AND conrelid::regclass::text IN ('audit_log_changes', 'bot_messages')
        SQL))->map(fn ($c) => "{$c->child}<{$c->parent}")->sort()->values()->all();

    expect($cascades)->toBe(['audit_log_changes<audit_log', 'bot_messages<bot_conversations']);
});
