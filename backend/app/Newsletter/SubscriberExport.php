<?php

namespace App\Newsletter;

use App\Audit\AuditLogger;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The subscribers as Excel (19.9: export), with their consent facts: when they subscribed and confirmed, from which page
 * and form. Every cell is text, so an address or name starting with "=" never becomes a formula. Each export is audited.
 *
 * @param  list<string>|null  $statuses
 */
final class SubscriberExport
{
    /** @param  list<string>|null  $statuses */
    public static function xlsx(?array $statuses, ?int $userId): string
    {
        $path = tempnam(sys_get_temp_dir(), 'subx');
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('المشتركون');
        $sheet->setSheetView((new SheetView)->setRightToLeft(true));
        $row = fn (array $cells, ?Style $s = null) => new Row(array_map(fn ($v) => new StringCell((string) ($v ?? ''), $s), $cells));

        $writer->addRow($row(['البريد', 'الاسم', 'الحالة', 'اشترك (UTC)', 'أكّد (UTC)', 'ألغى (UTC)', 'الصفحة', 'المكان'], (new Style)->setFontBold()));
        $count = 0;
        DB::table('subscribers')->when($statuses, fn ($q) => $q->whereIn('status', $statuses))->orderBy('id')
            ->select(['id', 'email', 'name', 'status', 'subscribed_at', 'confirmed_at', 'unsubscribed_at', 'source_path', 'source_placement'])
            ->chunkById(500, function ($rows) use ($writer, $row, &$count) {
                foreach ($rows as $s) {
                    $writer->addRow($row([$s->email, $s->name, Subscriber::STATUSES[$s->status] ?? $s->status, $s->subscribed_at, $s->confirmed_at,
                        $s->unsubscribed_at, $s->source_path, Subscriber::PLACEMENTS[$s->source_placement] ?? $s->source_placement]));
                    $count++;
                }
            });
        $writer->close();
        AuditLogger::record('export', null, null, ['subscribers.rows' => [null, (string) $count]], $userId);

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
