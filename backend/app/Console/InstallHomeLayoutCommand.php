<?php

namespace App\Console;

use App\Navigation\HomeLayout;
use Illuminate\Console\Command;

class InstallHomeLayoutCommand extends Command
{
    protected $signature = 'home:install';

    protected $description = 'Write the default order of the home page if it has none yet; an existing order is left alone';

    public function handle(HomeLayout $layout): int
    {
        $this->info('Added '.$layout->install().' home block(s).');

        return self::SUCCESS;
    }
}
