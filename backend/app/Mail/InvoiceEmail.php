<?php

namespace App\Mail;

use App\Media\MediaStorage;
use App\Models\MediaAsset;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\DB;

/** The payment receipt with the invoice's PDF attached (16.9). The file is read when the message is sent. */
class InvoiceEmail extends TemplatedEmail
{
    /** @param  array<string, scalar|null>  $vars */
    public function __construct(public readonly int $invoiceId, array $vars)
    {
        parent::__construct('payment_receipt', $vars);
    }

    public function attachments(): array
    {
        $invoice = DB::table('invoices')->where('id', $this->invoiceId)->first(['number', 'pdf_media_id']);
        $asset = $invoice?->pdf_media_id ? MediaAsset::query()->find($invoice->pdf_media_id) : null;
        if ($asset === null) {
            return [];
        }

        return [Attachment::fromData(fn () => app(MediaStorage::class)->read($asset), $invoice->number.'.pdf')->withMime('application/pdf')];
    }
}
