<?php

namespace App\Console;

use App\Awareness\CharacterCatalog;
use Illuminate\Console\Command;

class InstallCharactersCommand extends Command
{
    protected $signature = 'characters:install';

    protected $description = 'Add the eight awareness characters with their first names (existing ones are not touched)';

    public function handle(CharacterCatalog $catalog): int
    {
        $this->info('Added '.$catalog->install().' character(s).');

        return self::SUCCESS;
    }
}
