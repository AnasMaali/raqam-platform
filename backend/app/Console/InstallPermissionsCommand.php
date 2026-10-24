<?php

namespace App\Console;

use App\Access\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Writes the permissions of the catalogue that are missing (decision 2.7); never removes or renames one. Run at every deploy. */
class InstallPermissionsCommand extends Command
{
    protected $signature = 'permissions:install';

    protected $description = 'Add the missing admin-team permissions of the catalogue';

    public function handle(): int
    {
        $added = 0;
        foreach (PermissionCatalog::PERMISSIONS as $code => $name) {
            $added += DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name]);
        }
        $this->info("permissions added: {$added}");

        return self::SUCCESS;
    }
}
