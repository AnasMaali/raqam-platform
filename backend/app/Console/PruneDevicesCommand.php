<?php

namespace App\Console;

use App\Auth\KnownDevices;
use Illuminate\Console\Command;

class PruneDevicesCommand extends Command
{
    protected $signature = 'devices:prune';

    protected $description = 'Forget admin devices not seen for six months';

    public function handle(KnownDevices $devices): int
    {
        $this->info("Forgot {$devices->prune()} devices.");

        return self::SUCCESS;
    }
}
