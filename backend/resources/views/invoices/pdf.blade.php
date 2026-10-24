{{-- The invoice (16.9). Everything is escaped; no address, image or font is fetched while it is drawn (OfflinePdf). --}}
@php($i = $invoice)
<style>
    body { font-family: sans-serif; font-size: 11pt; color: #1a1a1a; }
    h1 { font-size: 18pt; margin: 0 0 4mm; }
    table { width: 100%; border-collapse: collapse; margin-top: 5mm; }
    th, td { border-bottom: 0.3mm solid #ccc; padding: 2mm; text-align: right; }
    th { background: #f2f2f2; }
    .num { direction: ltr; text-align: left; }
    .muted { color: #666; font-size: 9pt; }
</style>
<h1>{{ $site }} — فاتورة</h1>
<table>
    <tr><th>رقم الفاتورة</th><td class="num">{{ $i->number }}</td><th>التاريخ</th><td class="num">{{ \Carbon\CarbonImmutable::parse($i->issued_at)->setTimezone('Asia/Hebron')->format('Y-m-d H:i') }}</td></tr>
    <tr><th>رقم الحجز</th><td class="num">{{ $i->reference }}</td><th>العملة</th><td class="num">{{ trim($i->currency_code) }}</td></tr>
</table>
<table>
    <tr><th>العميل</th><td>{{ $i->billed_name }}</td></tr>
    @if ($i->billed_company)<tr><th>الشركة</th><td>{{ $i->billed_company }}</td></tr>@endif
    <tr><th>البريد</th><td class="num">{{ $i->billed_email }}</td></tr>
    @if ($i->billed_phone)<tr><th>الهاتف</th><td class="num">{{ $i->billed_phone }}</td></tr>@endif
    @if ($i->billed_tax_number)<tr><th>الرقم الضريبي للعميل</th><td class="num">{{ $i->billed_tax_number }}</td></tr>@endif
</table>
<table>
    <tr><th>#</th><th>البند</th><th>الكمية</th><th>سعر الوحدة</th></tr>
    @foreach ($lines as $line)
        <tr><td class="num">{{ $line->line_no }}</td><td>{{ $line->description }}</td><td class="num">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td><td class="num">{{ $money($line->unit_amount) }}</td></tr>
    @endforeach
</table>
<table>
    <tr><th>المجموع قبل الضريبة</th><td class="num">{{ $money($i->subtotal) }}</td></tr>
    @if ($i->tax_rate_bp !== null)<tr><th>الضريبة ({{ $i->tax_rate_bp / 100 }}%)</th><td class="num">{{ $money($i->tax_amount) }}</td></tr>@endif
    <tr><th>المجموع</th><td class="num"><strong>{{ $money($i->total_amount) }}</strong></td></tr>
</table>
@if ($i->seller_tax_number)<p class="muted">الرقم الضريبي للبائع: {{ $i->seller_tax_number }}</p>@endif
<p class="muted">فاتورة صادرة إلكترونياً عن دفعة مستلمة.</p>
