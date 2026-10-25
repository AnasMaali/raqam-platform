<?php

namespace App\Console;

use Illuminate\Console\Command;

/**
 * Everything a new or updated installation needs, in the order it needs it, safe to run again on every deploy: the
 * migrations, then each install command (each adds only what is missing and never touches what the admin changed),
 * then the search engine's settings when the engine is configured.
 */
class RaqamInstallCommand extends Command
{
    protected $signature = 'raqam:install {--no-migrate : Skip the migrations (they already ran)}';

    protected $description = 'Run the migrations and every install command in order; safe to repeat';

    /**
     * The order matters: the chatbot's answers link to the pages and services, the home page lists the categories and
     * sections (seeded by the migrations), the permissions come first so the panel is complete from the start.
     *
     * @var array<string, string> command => what it adds
     */
    public const STEPS = [
        'permissions:install' => 'admin-team permissions',
        'pages:install' => 'standard pages (unpublished drafts)',
        'characters:install' => 'the eight awareness characters',
        'cities:install' => 'the twelve weather cities',
        'services:install' => 'the six starting services (hidden)',
        'emails:install' => 'e-mail templates',
        'synonyms:install' => 'the starter synonyms',
        'bot:install' => 'the chatbot\'s starting answers and buttons',
        'home:install' => 'the default order of the home page',
    ];

    public function handle(): int
    {
        if (! $this->option('no-migrate') && $this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('The migrations failed; nothing else was run.');

            return self::FAILURE;
        }

        foreach (self::STEPS as $command => $what) {
            $this->info("→ {$command} ({$what})");
            if ($this->call($command) !== self::SUCCESS) {
                $this->error("{$command} failed; the steps after it were not run. Fix it and run raqam:install again.");

                return self::FAILURE;
            }
        }

        // The engine is optional outside production (an empty key = search runs on PostgreSQL); production requires it.
        if (filled(config('raqam.search.key'))) {
            $this->info('→ search:setup (index settings of the search engine)');
            if ($this->call('search:setup') !== self::SUCCESS) {
                $this->error('search:setup failed. Fix it and run raqam:install again.');

                return self::FAILURE;
            }
        } else {
            $this->line('skipped: search:setup (no search engine key)');
        }

        $this->info('Installation complete.');

        return self::SUCCESS;
    }
}
