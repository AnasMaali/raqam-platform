<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * For create and edit pages: when the database refuses a save (a rule only it can see, like "one level
 * of sub-categories", or a clash with something saved a moment ago) the admin gets a sentence in Arabic
 * and the form stays as it was, instead of an error page.
 */
trait ReportsDatabaseRefusals
{
    protected function handleRecordCreation(array $data): Model
    {
        return $this->reportRefusals(fn () => parent::handleRecordCreation($data));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->reportRefusals(fn () => parent::handleRecordUpdate($record, $data));
    }

    private function reportRefusals(Closure $save): Model
    {
        try {
            return DB::transaction($save);
        } catch (QueryException $e) {
            $message = $this->refusalMessage($e) ?? throw $e;

            Notification::make()->danger()->persistent()->title('تعذّر الحفظ')->body($message)->send();
            $this->halt();
        }
    }

    /**
     * The database's own sentences are English; the refusals people can meet are put in Arabic here.
     * Anything else is a bug and is not hidden (null).
     */
    public function refusalMessage(QueryException $e): ?string
    {
        return match ($e->getCode()) {
            '23514' => match (true) {
                str_contains($e->getMessage(), 'one level') => 'المستوى الفرعي واحد فقط: لا يمكن وضع تصنيف تحت تصنيف فرعي.',
                str_contains($e->getMessage(), 'cannot become a sub-category') => 'هذا التصنيف له تصنيفات فرعية، فلا يمكن جعله فرعياً.',
                default => 'إحدى القيم مرفوضة، راجع الحقول وحاول مجدداً.',
            },
            '23505' => 'هذه القيمة مستعملة مسبقاً في سجل آخر.',
            '23503' => 'أحد العناصر المختارة لم يعد موجوداً، حدّث الصفحة وأعد الاختيار.',
            '22P02', '22003', '22001' => 'إحدى القيم غير صالحة أو أطول من المسموح.',
            default => null,
        };
    }
}
