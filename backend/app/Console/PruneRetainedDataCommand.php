<?php

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * How long visitors' own words are kept (decision 2026-10-24): chatbot conversations `chat_conversations_days` days from
 * when they began (a conversation lives one day at most, so all its messages go with it), contact messages
 * `contact_messages_days` days from when they arrived. Days are counted by the database clock.
 */
class PruneRetainedDataCommand extends Command
{
    protected $signature = 'retention:prune';

    protected $description = 'Delete chatbot conversations, contact messages, spent e-mail codes, forged payment notifications, old weather readings and long-read admin notifications past their retention';

    public function handle(): int
    {
        $chatDays = (int) config('raqam.retention.chat_conversations_days');
        $contactDays = (int) config('raqam.retention.contact_messages_days');

        $conversations = DB::table('bot_conversations')->where('started_at', '<', DB::raw("now() - make_interval(days => {$chatDays})"))->delete();
        $messages = DB::table('contact_messages')->where('created_at', '<', DB::raw("now() - make_interval(days => {$contactDays})"))->delete();

        // E-mail codes (sign-in, newsletter, accounts) are worthless once spent or expired; a day later they go (phase 10 review).
        $codes = DB::table('verification_codes')->where(fn ($q) => $q->where('expires_at', '<', DB::raw("now() - interval '1 day'"))
            ->orWhere('consumed_at', '<', DB::raw("now() - interval '1 day'")))->delete();
        // Notifications whose signature was not the bank's are kept for inquiry, not for ever; real ones stay (16.6).
        $forgedDays = (int) config('raqam.retention.forged_payment_events_days');
        $forged = DB::table('payment_events')->where('signature_valid', false)->where('received_at', '<', DB::raw("now() - make_interval(days => {$forgedDays})"))->delete();

        // The platform's own records (decision QA-7): weather readings once old (only today's are shown), and an admin's
        // notifications some time after they were read; an unread one stays until it is read.
        $weatherDays = (int) config('raqam.retention.weather_observations_days');
        $readDays = (int) config('raqam.retention.read_admin_notifications_days');
        $readings = DB::table('weather_observations')->where('observed_at', '<', DB::raw("now() - make_interval(days => {$weatherDays})"))->delete();
        $notifications = DB::table('admin_notifications')->whereNotNull('read_at')->where('read_at', '<', DB::raw("now() - make_interval(days => {$readDays})"))->delete();

        $this->info("Deleted {$conversations} chatbot conversations (older than {$chatDays} days) and {$messages} contact messages (older than {$contactDays} days).");
        $this->info("Deleted {$readings} weather readings (older than {$weatherDays} days) and {$notifications} admin notifications (read more than {$readDays} days ago).");
        $this->info("Deleted {$codes} spent or expired e-mail codes and {$forged} payment notifications with a forged signature.");

        return self::SUCCESS;
    }
}
