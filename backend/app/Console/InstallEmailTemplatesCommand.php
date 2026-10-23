<?php

namespace App\Console;

use App\Mail\EmailTemplates;
use Illuminate\Console\Command;

/** Makes the row of every system message that has none yet (20.7). Never touches a text the admin edited. */
class InstallEmailTemplatesCommand extends Command
{
    protected $signature = 'emails:install';

    protected $description = 'Add the e-mail templates that do not exist yet, without touching edited ones';

    public function handle(): int
    {
        $this->info('Added '.EmailTemplates::install().' e-mail templates.');

        return self::SUCCESS;
    }
}
