<?php

namespace App\Console;

use App\Newsletter\BrevoClient;
use App\Newsletter\BrevoUnavailable;
use Illuminate\Console\Command;

/** One-time setup in Brevo: the contact attribute with each subscriber's unsubscribe link, and the webhook. */
class BrevoSetupCommand extends Command
{
    protected $signature = 'newsletter:brevo-setup {url : The API address Brevo calls, e.g. https://api.example/api/v1/newsletter/brevo-webhook}';

    protected $description = 'Create the Brevo contact attribute and the unsubscribe/bounce webhook';

    public function handle(BrevoClient $brevo): int
    {
        $url = (string) $this->argument('url');
        $token = (string) config('raqam.newsletter.brevo.webhook_token');
        if (! BrevoClient::configured() || strlen($token) < 32 || ! str_starts_with($url, 'https://')) {
            $this->error('Set BREVO_API_KEY, BREVO_LIST_ID and BREVO_WEBHOOK_TOKEN (32+ characters), and give an https:// address.');

            return self::FAILURE;
        }
        try {
            $brevo->setUp($url, $token);
        } catch (BrevoUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Brevo is set up: attribute '.BrevoClient::UNSUBSCRIBE_ATTRIBUTE.' and the webhook.');

        return self::SUCCESS;
    }
}
