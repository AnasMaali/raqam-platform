<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Bulk actions for the panel's lists (21.17). Each record is changed through its model, one by one, so the audit trail, the
 * site's webhooks, the search index and every other rule run exactly as for a single change. A record the database refuses
 * to delete (still used somewhere) is skipped and counted, never half deleted.
 */
final class BulkRecords
{
    /** @param  (Closure(Model): bool)|null  $allowed  records it does not apply to are left alone and counted */
    public static function delete(?Closure $allowed = null, string $label = 'حذف المحدد'): BulkAction
    {
        return BulkAction::make('deleteSelected')->label($label)->icon(Heroicon::OutlinedTrash)->color('danger')
            ->requiresConfirmation()->modalDescription('يُحذف كل عنصر محدد كما لو حذفته وحده؛ ما زال مستخدماً لا يُحذف.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($allowed) {
                $done = $skipped = 0;
                foreach ($records as $record) {
                    if ($allowed !== null && ! $allowed($record)) {
                        $skipped++;

                        continue;
                    }
                    try {
                        // Its own (savepoint) transaction: a refused statement must not poison the next one.
                        DB::transaction(fn () => $record->delete());
                        $done++;
                    } catch (QueryException $e) {
                        if ($e->getCode() !== '23503') {   // foreign_key_violation: still used
                            throw $e;
                        }
                        $skipped++;
                    }
                }
                self::report($done, $skipped, 'حُذف', 'لم يُحذف');
            });
    }

    /**
     * Two actions that set a yes/no column on every selected record (show/hide, publish/unpublish, on/off).
     *
     * @param  (Closure(Model): bool)|null  $allowed
     * @return list<BulkAction>
     */
    public static function toggle(string $column, string $onLabel, string $offLabel, ?Closure $allowed = null): array
    {
        $set = fn (bool $value) => function (Collection $records) use ($column, $value, $allowed) {
            $done = $skipped = 0;
            foreach ($records as $record) {
                if ($allowed !== null && ! $allowed($record, $value)) {
                    $skipped++;

                    continue;
                }
                DB::transaction(fn () => $record->update([$column => $value]));
                $done++;
            }
            self::report($done, $skipped, 'تم تغيير', 'لم يتغيّر');
        };

        return [
            BulkAction::make($column.'On')->label($onLabel)->icon(Heroicon::OutlinedEye)->deselectRecordsAfterCompletion()->action($set(true)),
            BulkAction::make($column.'Off')->label($offLabel)->icon(Heroicon::OutlinedEyeSlash)->deselectRecordsAfterCompletion()->action($set(false)),
        ];
    }

    private static function report(int $done, int $skipped, string $doneWord, string $skippedWord): void
    {
        $note = Notification::make()->title("{$doneWord}: {$done}")->{$skipped > 0 ? 'warning' : 'success'}();
        if ($skipped > 0) {
            $note->body("{$skippedWord}: {$skipped} (مستخدم في مكان آخر أو غير مسموح).");
        }
        $note->send();
    }
}
