<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * A record of what happened (the audit trail, the sign-in attempts): written by its own service, never through a model,
 * and never changed or removed from the application (22.15). Saving or deleting one through the model is a bug.
 */
trait ReadOnlyRecord
{
    protected static function bootReadOnlyRecord(): void
    {
        $refuse = fn () => throw new LogicException(static::class.' is read only: it is never changed or deleted from the application.');
        static::saving($refuse);
        static::deleting($refuse);
    }
}
