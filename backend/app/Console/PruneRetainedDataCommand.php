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

    protected $description = 'Delete chatbot conversations and contact messages older than their retention periods';

    public function handle(): int
    {
        $chatDays = (int) config('raqam.retention.chat_conversations_days');
        $contactDays = (int) config('raqam.retention.contact_messages_days');

        $conversations = DB::table('bot_conversations')->where('started_at', '<', DB::raw("now() - make_interval(days => {$chatDays})"))->delete();
        $messages = DB::table('contact_messages')->where('created_at', '<', DB::raw("now() - make_interval(days => {$contactDays})"))->delete();

        $this->info("Deleted {$conversations} chatbot conversations (older than {$chatDays} days) and {$messages} contact messages (older than {$contactDays} days).");

        return self::SUCCESS;
    }
}
