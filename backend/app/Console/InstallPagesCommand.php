<?php

namespace App\Console;

use App\Content\StaticPages;
use Illuminate\Console\Command;

class InstallPagesCommand extends Command
{
    protected $signature = 'pages:install';

    protected $description = 'Create the standard static pages (about, privacy, terms ...) as unpublished drafts; pages that exist are left alone';

    public function handle(StaticPages $pages): int
    {
        $this->info('Added '.$pages->install().' page(s).');

        return self::SUCCESS;
    }
}
