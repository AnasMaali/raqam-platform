<?php

namespace App\Jobs;

use App\Mail\InvoiceEmail;
use App\Mail\SystemMail;
use App\Media\MediaUploader;
use App\Payments\Invoices;
use App\Services\Money;
use App\Support\OfflinePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Draws the invoice's PDF, keeps it as a private file attached to the invoice (once, 16.9), and mails it to the customer
 * with the receipt. Run again after a failure it reuses the attached PDF and never attaches a second one.
 */
class SendInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $invoiceId) {}

    public function handle(Invoices $invoices, MediaUploader $uploader): void
    {
        $doc = $invoices->document($this->invoiceId);
        if ($doc['invoice']->pdf_media_id === null) {
            $file = tempnam(sys_get_temp_dir(), 'inv');
            try {
                file_put_contents($file, self::pdf($doc));
                $media = $uploader->store($file, $doc['invoice']->number.'.pdf', ['visibility' => 'private'], null)->asset;
            } finally {
                @unlink($file);
            }
            DB::table('invoices')->where('id', $this->invoiceId)->whereNull('pdf_media_id')->update(['pdf_media_id' => $media->id]);
        }

        $name = DB::table('invoices as i')->join('payments as p', 'p.id', '=', 'i.payment_id')->join('bookings as b', 'b.id', '=', 'p.booking_id')
            ->join('customers as c', 'c.id', '=', 'b.customer_id')->where('i.id', $this->invoiceId)->first(['c.name', 'c.email']);
        SystemMail::queue(new InvoiceEmail($this->invoiceId, ['الاسم' => $name->name, 'المرجع' => $doc['invoice']->reference, 'الفاتورة' => $doc['invoice']->number]),
            (string) $name->email);
    }

    /** @param  array<string, mixed>  $doc */
    public static function pdf(array $doc): string
    {
        $pdf = OfflinePdf::make(['mode' => 'utf-8', 'format' => 'A4', 'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetTitle('فاتورة '.$doc['invoice']->number);
        $money = fn (int|string $minor) => Money::display((int) $minor, (int) $doc['invoice']->minor_units).' '.$doc['invoice']->symbol;
        $pdf->WriteHTML(view('invoices.pdf', [...$doc, 'money' => $money])->render());

        return $pdf->Output('', 'S');
    }
}
