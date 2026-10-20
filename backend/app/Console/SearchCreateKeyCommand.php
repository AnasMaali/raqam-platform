<?php

namespace App\Console;

use App\Search\MeiliClient;
use App\Search\SearchUnavailable;
use Illuminate\Console\Command;

class SearchCreateKeyCommand extends Command
{
    protected $signature = 'search:create-key
        {--prefix= : Index prefix the key is limited to (default: MEILISEARCH_PREFIX)}
        {--env-file= : Write the new key into this .env file as MEILISEARCH_KEY instead of printing it}';

    protected $description = 'With the master key, create the scoped API key the application uses (its indexes only)';

    /** The version this platform is built and tested on (decision 10, pinned). */
    public const VERSION = '1.54.3';

    public function handle(): int
    {
        $prefix = (string) ($this->option('prefix') ?? config('raqam.search.prefix'));
        if (preg_match('/^[a-z0-9_]{2,40}$/', $prefix) !== 1) {
            $this->error('The prefix must be 2 to 40 lowercase letters, digits or underscores.');

            return self::INVALID;
        }

        // The master key is read once, hidden, and never stored by the application.
        $master = (string) (getenv('MEILISEARCH_MASTER_KEY') ?: $this->secret('Meilisearch master key'));
        $meili = new MeiliClient(rtrim((string) config('raqam.search.host'), '/'), $master);

        try {
            if (! $meili->canListKeys()) {
                $this->error('That is not the master key (it cannot manage API keys).');

                return self::FAILURE;
            }
            $version = $meili->version();
            if ($version !== self::VERSION) {
                $this->error("Meilisearch is {$version}; this platform is pinned to ".self::VERSION.'.');

                return self::FAILURE;
            }
            $key = $meili->createKey('Raqam application ('.$prefix.'*)', [$prefix.'*']);
        } catch (SearchUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $file = $this->option('env-file');
        if ($file === null) {
            $this->line($key);
            $this->info("Set MEILISEARCH_KEY to the key above and MEILISEARCH_PREFIX={$prefix}. It is shown once.");

            return self::SUCCESS;
        }

        if (! is_file($file) || ! is_writable($file)) {
            $this->error("Cannot write {$file}.");

            return self::FAILURE;
        }
        $env = (string) file_get_contents($file);
        $lines = ['MEILISEARCH_KEY' => $key, 'MEILISEARCH_PREFIX' => $prefix];
        foreach ($lines as $name => $value) {
            $env = preg_match("/^{$name}=.*$/m", $env)
                ? preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env)
                : rtrim($env, "\n")."\n{$name}={$value}\n";
        }
        file_put_contents($file, $env);
        $this->info("Wrote MEILISEARCH_KEY and MEILISEARCH_PREFIX={$prefix} into {$file}.");

        return self::SUCCESS;
    }
}
