<?php

namespace App\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Issues the invoice of a captured payment (16.9), inside the same transaction that records the payment: the number comes
 * from the database's yearly counter (INV-<year>-<00001>), so a rolled-back payment leaves no gap. The customer's details
 * are copied as they are at this moment (a legal fact); the database keeps the invoice and its lines unchangeable,
 * except for attaching the PDF once. Totals are a view (`invoice_totals`); tax fields stay empty until the tax decision.
 */
final class Invoices
{
    public function issue(int $paymentId): int
    {
        $p = DB::selectOne('SELECT p.amount, p.currency_code, b.reference, s.name_ar AS service, c.name, c.email, c.phone, c.company_name
                            FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN services s ON s.id = b.service_id
                            JOIN customers c ON c.id = b.customer_id WHERE p.id = ?', [$paymentId]);
        $id = DB::table('invoices')->insertGetId([
            'payment_id' => $paymentId, 'billed_name' => $p->name, 'billed_email' => $p->email, 'billed_phone' => $p->phone,
            'billed_company' => $p->company_name, 'currency_code' => trim($p->currency_code), 'tax_rate_bp' => null,
        ]);
        DB::table('invoice_lines')->insert([
            'invoice_id' => $id, 'line_no' => 1, 'description' => mb_substr("{$p->service} — حجز {$p->reference}", 0, 300),
            'quantity' => 1, 'unit_amount' => (int) $p->amount,
        ]);

        return $id;
    }

    /** @return array<string, mixed> everything the PDF shows */
    public function document(int $invoiceId): array
    {
        $i = DB::selectOne('SELECT i.*, t.subtotal, t.tax_amount, t.total_amount, c.symbol, c.minor_units, b.reference
                            FROM invoices i JOIN invoice_totals t ON t.invoice_id = i.id JOIN currencies c ON c.code = i.currency_code
                            JOIN payments p ON p.id = i.payment_id JOIN bookings b ON b.id = p.booking_id WHERE i.id = ?', [$invoiceId]);
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('line_no')->get();

        return ['invoice' => $i, 'lines' => $lines, 'site' => (string) (DB::table('settings')->where('key', 'general.site_name')->value('value') ?: 'رَقَم')];
    }
}
