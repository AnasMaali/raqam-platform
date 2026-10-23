<?php

namespace App\Console;

use App\Panel\WeeklyDigest;
use Illuminate\Console\Command;

class WeeklyDigestCommand extends Command
{
    protected $signature = 'admin:weekly-digest';

    protected $description = 'E-mail the weekly summary to every active admin';

    public function handle(): int
    {
        $this->info('Queued the weekly summary to '.WeeklyDigest::send().' admins.');

        return self::SUCCESS;
    }
}
