<html dir="rtl" lang="ar">
<head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 11pt; color: #1f2937; }
    h1 { font-size: 16pt; margin: 0 0 4mm; }
    .meta td { padding: 1mm 0 1mm 6mm; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 5mm; }
    table.data th, table.data td { border: 0.2mm solid #9ca3af; padding: 1.5mm 2mm; text-align: center; }
    table.data th { background: #f3f4f6; }
    .num { direction: ltr; }
    .total td { font-weight: bold; background: #f9fafb; }
</style>
</head>
<body>
<h1>تقرير حملة إعلانية</h1>
<table class="meta">
    <tr><td>الحملة:</td><td>{{ $r['campaign']->name }}</td></tr>
    <tr><td>المعلن:</td><td>{{ $r['campaign']->advertiser?->organization?->name ?? '—' }}</td></tr>
    <tr><td>الفترة:</td><td><span class="num">{{ $r['from'] }} — {{ $r['to'] }}</span> (بتوقيت فلسطين)</td></tr>
</table>

<table class="data">
    <tr><th>المشاهدات</th><th>النقرات</th><th>نسبة النقر</th></tr>
    <tr class="total"><td class="num">{{ number_format($r['total']['impressions']) }}</td><td class="num">{{ number_format($r['total']['clicks']) }}</td><td class="num">{{ $r['total']['ctr'] === null ? '—' : $r['total']['ctr'].'%' }}</td></tr>
</table>

<table class="data">
    <tr><th>المكان</th><th>المشاهدات</th><th>النقرات</th><th>نسبة النقر</th></tr>
    @foreach ($r['placements'] as $p)
        <tr><td>{{ $p['name'] }}</td><td class="num">{{ number_format($p['impressions']) }}</td><td class="num">{{ number_format($p['clicks']) }}</td><td class="num">{{ $p['ctr'] === null ? '—' : $p['ctr'].'%' }}</td></tr>
    @endforeach
</table>

<table class="data">
    <tr><th>اليوم</th><th>المشاهدات</th><th>النقرات</th><th>نسبة النقر</th></tr>
    @foreach ($r['days'] as $d)
        <tr><td class="num">{{ $d['date'] }}</td><td class="num">{{ number_format($d['impressions']) }}</td><td class="num">{{ number_format($d['clicks']) }}</td><td class="num">{{ $d['ctr'] === null ? '—' : $d['ctr'].'%' }}</td></tr>
    @endforeach
</table>
</body>
</html>
