<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * For resources whose key is a number. Without it a path like /records/create reaches PostgreSQL as a
 * bigint comparison and ends in a 500 instead of a 404.
 */
trait HasNumericRecordKey
{
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (! ctype_digit((string) $key) || strlen((string) $key) > 18) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }
}
