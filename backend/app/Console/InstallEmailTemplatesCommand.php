<?php

namespace App\Console;

use App\Mail\EmailTemplates;
use Illuminate\Console\Command;

/** Makes the row of every system message that has none yet (20.7), and brings unedited ones up to the catalogue. Never touches a text the admin edited. */
class InstallEmailTemplatesCommand extends Command
{
    protected $signature = 'emails:install';

    protected $description = 'Add the missing e-mail templates and update the ones nobody edited, without touching edited ones';

    public function handle(): int
    {
        $done = EmailTemplates::install();
        $this->info("Added {$done['added']} e-mail templates; updated {$done['updated']} that nobody had edited.");

        return self::SUCCESS;
    }
}
