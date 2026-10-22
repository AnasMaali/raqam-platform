<?php

namespace App\Ads;

use App\Support\OfflinePdf;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A campaign report as Excel (OpenSpout, MIT) or PDF (mPDF, GPL-2.0), both free (decision 13.8), right to left.
 * Every text cell is written as a string cell, so a name that starts with "=" stays text and never becomes a formula.
 */
final class AdReportExport
{
    public static function filename(array $report, string $extension): string
    {
        return 'ad-report-'.$report['campaign']->id.'-'.$report['from'].'-'.$report['to'].'.'.$extension;
    }

    public function xlsx(array $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'adx');
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('التقرير');
        $sheet->setSheetView((new SheetView)->setRightToLeft(true));
        $bold = (new Style)->setFontBold();

        $text = fn (string $v, ?Style $s = null) => new StringCell($v, $s);
        $row = fn (array $cells, ?Style $s = null) => new Row(array_map(fn ($v) => is_int($v) ? Cell::fromValue($v, $s) : $text((string) ($v ?? '—'), $s), $cells));

        $c = $report['campaign'];
        $writer->addRow($row(['تقرير حملة إعلانية'], $bold));
        $writer->addRow($row(['الحملة', $c->name]));
        $writer->addRow($row(['المعلن', $c->advertiser?->organization?->name ?? '—']));
        $writer->addRow($row(['الفترة', $report['from'].' — '.$report['to'].' (بتوقيت فلسطين)']));
        $writer->addRow($row(['المجموع', 'المشاهدات', $report['total']['impressions'], 'النقرات', $report['total']['clicks'], 'نسبة النقر %', $report['total']['ctr']]));
        $writer->addRow(new Row([]));

        $writer->addRow($row(['اليوم', 'المشاهدات', 'النقرات', 'نسبة النقر %'], $bold));
        foreach ($report['days'] as $d) {
            $writer->addRow($row([$d['date'], $d['impressions'], $d['clicks'], $d['ctr']]));
        }
        $writer->addRow(new Row([]));
        $writer->addRow($row(['المكان', 'المشاهدات', 'النقرات', 'نسبة النقر %'], $bold));
        foreach ($report['placements'] as $p) {
            $writer->addRow($row([$p['name'], $p['impressions'], $p['clicks'], $p['ctr']]));
        }
        $writer->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function pdf(array $report): string
    {
        // Nothing is read while the page is drawn: the HTML carries no addresses, and OfflinePdf answers every fetch empty.
        $pdf = OfflinePdf::make(['mode' => 'utf-8', 'format' => 'A4', 'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
            'margin_top' => 14, 'margin_bottom' => 14]);
        $pdf->SetTitle('تقرير حملة إعلانية');
        $pdf->showImageErrors = false;
        $pdf->WriteHTML(view('reports.ad-campaign', ['r' => $report])->render());

        return $pdf->Output('', 'S');
    }
}
