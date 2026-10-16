<?php

namespace App\Console;

use Illuminate\Database\Console\WipeCommand;

/**
 * db:wipe (and therefore migrate:fresh) for PostgreSQL drops the whole public
 * schema instead of only its tables. The schema migrations also create
 * functions, domains and extensions, which Laravel's wipe leaves behind, so
 * the next migrate would fail with "already exists".
 *
 * Production guards (prohibit, confirmation) are inherited from WipeCommand.
 */
class WipeDatabaseCommand extends WipeCommand
{
    protected function dropAllTables($database)
    {
        $connection = $this->laravel['db']->connection($database);

        if ($connection->getDriverName() !== 'pgsql') {
            parent::dropAllTables($database);

            return;
        }

        // Recreate public exactly as PostgreSQL 16 creates it in a new database.
        $connection->unprepared(<<<'SQL'
            DROP SCHEMA public CASCADE;
            CREATE SCHEMA public AUTHORIZATION pg_database_owner;
            GRANT USAGE ON SCHEMA public TO PUBLIC;
            COMMENT ON SCHEMA public IS 'standard public schema';
            SQL);
    }
}
