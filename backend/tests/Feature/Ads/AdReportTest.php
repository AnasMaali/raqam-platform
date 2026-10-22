<?php

use App\Ads\AdRejected;
use App\Ads\AdReport;
use App\Ads\AdReportExport;
use App\Support\OfflinePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

function adStats(int $campaign, string $placement, string $date, int $views, int $clicks): void
{
    DB::table('ad_stats_daily')->insert(['campaign_id' => $campaign, 'placement_code' => $placement, 'stat_date' => $date, 'impressions' => $views, 'clicks' => $clicks]);
}

test('the click rate is clicks ÷ views as a percentage, rounded half up to two decimals, in integers', function (int $views, int $clicks, ?string $rate) {
    expect(AdReport::ctr($views, $clicks))->toBe($rate);
})->with([
    [0, 0, null], [0, 5, null], [3, 1, '33.33'], [3, 2, '66.67'], [8, 1, '12.50'], [2, 1, '50.00'], [1, 1, '100.00'],
    [200000, 1, '0.00'], [20000, 1, '0.01'], [40000, 1, '0.00'], [39999, 1, '0.00'], [13333, 1, '0.01'], [1000, 0, '0.00'],
    [PHP_INT_MAX >> 20, 12345, '0.00'],
]);

test('a report sums per day and per slot for exactly the chosen days', function () {
    $c = adCampaign(['placements' => ['in_article'], 'desktop_media_id' => adImage(728, 90), 'mobile_media_id' => adImage(300, 250)]);
    adStats($c->id, 'in_article', '2026-10-01', 100, 3);
    adStats($c->id, 'in_article', '2026-10-03', 300, 1);
    adStats($c->id, 'header_leaderboard', '2026-10-03', 50, 50);   // a slot it was in before
    adStats($c->id, 'in_article', '2026-10-05', 999, 999);         // outside the period

    $r = app(AdReport::class)->build($c, '2026-10-04', '2026-10-01');   // given backwards: put in order
    expect($r['from'])->toBe('2026-10-01')->and($r['to'])->toBe('2026-10-04')
        ->and(collect($r['days'])->map(fn ($d) => [$d['date'], $d['impressions'], $d['clicks'], $d['ctr']])->all())->toBe([
            ['2026-10-01', 100, 3, '3.00'], ['2026-10-02', 0, 0, null], ['2026-10-03', 350, 51, '14.57'], ['2026-10-04', 0, 0, null],
        ])
        ->and(collect($r['placements'])->map(fn ($p) => [$p['code'], $p['impressions'], $p['clicks']])->all())->toBe([
            ['header_leaderboard', 50, 50], ['in_article', 400, 4],
        ])
        ->and($r['total'])->toBe(['impressions' => 450, 'clicks' => 54, 'ctr' => '12.00']);
});

test('a period is two real dates at most two years apart', function () {
    $c = adCampaign();
    expect(fn () => app(AdReport::class)->build($c, '2026-02-30', '2026-03-01'))->toThrow(AdRejected::class)
        ->and(fn () => app(AdReport::class)->build($c, 'yesterday', '2026-03-01'))->toThrow(AdRejected::class)
        ->and(fn () => app(AdReport::class)->build($c, '2024-01-01', '2026-01-02'))->toThrow(AdRejected::class, 'سنتين');
    expect(app(AdReport::class)->build($c, '2024-01-03', '2026-01-02')['days'])->toHaveCount(731);
});

test('the default period runs from the campaign start to its end or today, whichever comes first', function () {
    $c = adCampaign(['starts_at' => now('Asia/Hebron')->subDays(3)->utc()->format('Y-m-d H:i:s')]);
    expect(AdReport::defaultPeriod($c))->toBe([now('Asia/Hebron')->subDays(3)->toDateString(), now('Asia/Hebron')->toDateString()]);
});

test('the Excel copy is right to left, has the numbers, and keeps a name that looks like a formula as text', function () {
    $c = adCampaign(['name' => '=HYPERLINK("https://evil.example","انقر")']);
    adStats($c->id, 'header_leaderboard', '2026-10-01', 200, 7);
    $report = app(AdReport::class)->build($c, '2026-10-01', '2026-10-02');

    $path = tempnam(sys_get_temp_dir(), 'xt').'.xlsx';
    file_put_contents($path, app(AdReportExport::class)->xlsx($report));
    try {
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        expect($sheet)->toContain('rightToLeft="true"')->not->toContain('<f>');

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $s) {
            foreach ($s->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
    } finally {
        @unlink($path);
    }
    $flat = collect($rows)->flatten()->all();
    expect($flat)->toContain('=HYPERLINK("https://evil.example","انقر")', '2026-10-01', 200, 7, '3.50', '2026-10-02', 'بانر أعلى الصفحة');
});

test('the PDF is drawn from escaped HTML: a name cannot add markup (or make the PDF fetch an address)', function () {
    $c = adCampaign(['name' => '<img src="http://127.0.0.1:9/x"><b>حملة</b>']);
    $html = view('reports.ad-campaign', ['r' => app(AdReport::class)->build($c, '2026-10-01', '2026-10-01')])->render();

    expect($html)->not->toContain('<img')->not->toContain('<b>')->toContain('&lt;img src=&quot;http://127.0.0.1:9/x&quot;&gt;');
});

test('the PDF reads nothing while it is drawn: no file of the server, no address; pictures in the HTML itself still draw', function () {
    $root = sys_get_temp_dir().'/offline-pdf-'.bin2hex(random_bytes(4));
    mkdir($root);
    $png = $root.'/picture.png';
    imagepng(imagecreatetruecolor(40, 40), $png);
    // The same picture is also served over HTTP, so a fetch of the address would succeed if one were made.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:{$port}/picture.png") === false; $i++) {
        usleep(100_000);
    }
    $draw = function (string $html) {
        $pdf = OfflinePdf::make(['mode' => 'utf-8']);
        $pdf->showImageErrors = false;
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    };

    try {
        expect($draw('<p>نص</p><img src="'.$png.'">'))->not->toContain('/Subtype /Image')
            ->and($draw('<img src="file://'.$png.'">'))->not->toContain('/Subtype /Image')
            ->and($draw('<img src="http://127.0.0.1:'.$port.'/picture.png">'))->not->toContain('/Subtype /Image')
            ->and($draw('<img src="data:image/png;base64,'.base64_encode((string) file_get_contents($png)).'">'))->toContain('/Subtype /Image');
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($png);
        @rmdir($root);
    }
});

test('the PDF copy is a PDF with the campaign in it', function () {
    $c = adCampaign(['name' => 'حملة <b>الخريف</b> & الشتاء']);
    adStats($c->id, 'header_leaderboard', '2026-10-01', 200, 7);
    $pdf = app(AdReportExport::class)->pdf(app(AdReport::class)->build($c, '2026-10-01', '2026-10-01'));

    expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(strlen($pdf))->toBeGreaterThan(5000)
        ->and(AdReportExport::filename(['campaign' => $c, 'from' => '2026-10-01', 'to' => '2026-10-01'], 'pdf'))->toBe("ad-report-{$c->id}-2026-10-01-2026-10-01.pdf");

    if (($tool = trim((string) shell_exec('command -v pdftotext'))) !== '') {
        $file = tempnam(sys_get_temp_dir(), 'pt');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec(escapeshellarg($tool).' '.escapeshellarg($file).' - 2>/dev/null');
        @unlink($file);
        expect($text)->toContain('3.50%')->toContain('2026-10-01')->not->toContain('<b>');
    }
});
