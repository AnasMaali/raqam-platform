<?php

namespace App\Analytics;

use App\Audit\AuditLogger;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The statistics of a period as an Excel file (22.11), right to left, one sheet per table of the screen. Every text cell
 * is a string cell (a title starting with "=" stays text), and each export is written to the audit log (22.14).
 */
final class StatsExport
{
    public static function filename(StatsReport $report): string
    {
        return 'statistics-'.$report->data['from'].'-'.$report->data['to'].'.xlsx';
    }

    public static function xlsx(StatsReport $report, ?int $userId): string
    {
        $d = $report->data;
        $path = tempnam(sys_get_temp_dir(), 'stx');
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $row = fn (array $cells, ?Style $s = null) => new Row(array_map(fn ($v) => is_int($v) ? Cell::fromValue($v, $s) : new StringCell((string) ($v ?? '—'), $s), $cells));

        $sheet = function (string $name, array $head, array $rows, bool $first = false) use ($writer, $row, $bold) {
            $s = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $s->setName($name);
            $s->setSheetView((new SheetView)->setRightToLeft(true));
            $writer->addRow($row($head, $bold));
            foreach ($rows as $r) {
                $writer->addRow($row($r));
            }
        };

        $t = $d['totals'];
        $m = $d['modules'];
        $sheet('الملخص', ['البند', 'القيمة'], [
            ['الفترة (بتوقيت فلسطين)', $d['from'].' — '.$d['to']],
            ['مشاهدات الصفحات', $t['page_views']], ['الزوار (مجموع زوار الأيام)', $t['visitors']], ['الزيارات', $t['visits']],
            ['صفحات لكل زيارة', $t['pages_per_visit']], ['قراءات المواد', $t['readings']],
            ['عمليات البحث', $m['search']['searches']], ['بحث بلا نتائج', $m['search']['zero']],
            ['مشاهدات الإعلانات', $m['ads']['views']], ['نقرات الإعلانات', $m['ads']['clicks']], ['نسبة النقر %', $m['ads']['ctr']],
            ['مشتركون جدد بالنشرة', $m['newsletter']['joined']], ['ألغوا الاشتراك', $m['newsletter']['left']], ['المشتركون الآن', $m['newsletter']['active']],
            ['محادثات الشات بوت', $m['chatbot']['conversations']], ['أسئلة الشات بوت', $m['chatbot']['questions']], ['نسبة الإجابة %', $m['chatbot']['rate']],
            ['رسائل «اتصل بنا»', $m['contact']['messages']],
        ], first: true);
        $sheet('الأيام', ['اليوم', 'مشاهدات الصفحات', 'الزوار'], array_map(fn ($r) => [$r['date'], $r['page_views'], $r['visitors']], $d['days']));
        $sheet('المصادر', ['المصدر', 'الزيارات', 'النسبة %'], array_map(fn ($r) => [$r['name'], $r['count'], $r['share']], $d['sources']));
        $sheet('الأجهزة', ['الجهاز', 'الزيارات', 'النسبة %'], array_map(fn ($r) => [$r['name'], $r['count'], $r['share']], $d['devices']));
        $sheet('التفاعلات', ['التفاعل', 'العدد'], [
            ...array_map(fn ($r) => [$r['name'], $r['count']], $d['events']),
            ...array_map(fn ($r) => ['زر تواصل الخدمات: '.$r['name'], $r['count']], $d['contact_buttons']),
        ]);
        $sheet('الأكثر قراءة', ['المادة', 'النوع', 'التصنيف', 'القراءات'], array_map(fn ($r) => [$r['title'], $r['kind'], $r['category'], $r['count']], $d['posts']));
        $sheet('حسب التصنيف', ['التصنيف', 'القراءات', 'المواد'], array_map(fn ($r) => [$r['name'], $r['count'], $r['posts']], $d['by_category']));
        $sheet('حسب القسم', ['القسم', 'القراءات', 'المواد'], array_map(fn ($r) => [$r['name'], $r['count'], $r['posts']], $d['by_section']));
        $sheet('حسب الكاتب', ['الكاتب', 'القراءات', 'المواد'], array_map(fn ($r) => [$r['name'], $r['count'], $r['posts']], $d['by_author']));
        $writer->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);
        AuditLogger::record('export', null, null, ['statistics.period' => [null, $d['from'].' — '.$d['to']]], $userId);

        return $bytes;
    }
}
