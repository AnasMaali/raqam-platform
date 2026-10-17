<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Deleting a record that something still points at: say so in Arabic instead of showing a database error. */
final class Deletion
{
    /** @return bool whether the record was deleted */
    public static function attempt(Model $record, string $label, string $usedBy): bool
    {
        try {
            // Its own (savepoint) transaction: a refused statement must not poison one the caller has open.
            DB::transaction(fn () => $record->delete());
        } catch (QueryException $e) {
            if ($e->getCode() !== '23503') {   // foreign_key_violation
                throw $e;
            }

            Notification::make()->danger()->persistent()
                ->title("لا يمكن حذف «{$label}» لأنه مستخدم في {$usedBy}.")
                ->body('انقل ما يرتبط به أولاً، أو أخفِه بدل حذفه.')->send();

            return false;
        }

        Notification::make()->success()->title('تم الحذف.')->send();

        return true;
    }
}
