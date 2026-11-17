<?php

use App\Http\Middleware\QueryReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * The QA environment's query count (QUERY_REPORT, refused in production): the number of queries in each answer, and a
 * request that asks for an empty cache gets one of its own without touching the shared cache.
 */

function queryReportRoutes(): void
{
    Route::get('/qa-probe/three', function () {
        DB::select('select 1');
        DB::select('select 2');
        DB::select('select 3');

        return response('ok');
    });
    Route::get('/qa-probe/cached', fn () => response((string) Cache::remember('qa-probe', 60, fn () => DB::scalar('select 41 + 1'))));
}

beforeEach(function () {
    queryReportRoutes();
});

test('off, an answer says nothing about its queries', function () {
    config(['raqam.qa.query_report' => false]);

    $this->get('/qa-probe/three')->assertOk()->assertHeaderMissing(QueryReport::COUNT_HEADER);
});

test('on, each answer gives the number of its own queries, however many were made before it', function () {
    config(['raqam.qa.query_report' => true]);

    $this->get('/qa-probe/three')->assertHeader(QueryReport::COUNT_HEADER, '3');
    $this->get('/qa-probe/three')->assertHeader(QueryReport::COUNT_HEADER, '3');
});

test('a request with the cache off is answered from an empty cache of its own, and the shared one is left as it was', function () {
    config(['raqam.qa.query_report' => true]);

    $this->get('/qa-probe/cached')->assertSee('42')->assertHeader(QueryReport::COUNT_HEADER, '1');
    $this->get('/qa-probe/cached')->assertHeader(QueryReport::COUNT_HEADER, '0');

    // Twice: the empty cache does not keep what the first such request put in it.
    $this->get('/qa-probe/cached', [QueryReport::CACHE_HEADER => 'off'])->assertSee('42')->assertHeader(QueryReport::COUNT_HEADER, '1');
    $this->get('/qa-probe/cached', [QueryReport::CACHE_HEADER => 'off'])->assertHeader(QueryReport::COUNT_HEADER, '1');

    // The shared cache is the default again, still holds its value, and is the one used again.
    expect(Cache::getDefaultDriver())->toBe(config('cache.default'))->not->toBe('qa_cold')
        ->and(Cache::get('qa-probe'))->toBe(42);
    $this->get('/qa-probe/cached')->assertHeader(QueryReport::COUNT_HEADER, '0');
});

test('without the report, asking for the cache off changes nothing', function () {
    config(['raqam.qa.query_report' => false]);

    $this->get('/qa-probe/cached')->assertSee('42');
    DB::enableQueryLog();
    $this->get('/qa-probe/cached', [QueryReport::CACHE_HEADER => 'off'])->assertSee('42');

    expect(DB::getQueryLog())->toBe([]);
});

test('with a log file, every query of an answer is written with its time, one line per answer', function () {
    $file = tempnam(sys_get_temp_dir(), 'queries');
    config(['raqam.qa.query_report' => true, 'raqam.qa.query_log' => $file]);

    $this->get('/qa-probe/three');
    $this->get('/qa-probe/cached', [QueryReport::CACHE_HEADER => 'off']);

    $lines = array_map(fn ($line) => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES));
    unlink($file);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['path'])->toBe('/qa-probe/three')
        ->and($lines[0]['cache'])->toBe('on')
        ->and(array_column($lines[0]['queries'], 'sql'))->toBe(['select 1', 'select 2', 'select 3'])
        ->and($lines[0]['queries'][0]['ms'])->toBeFloat()
        ->and($lines[1]['cache'])->toBe('off')
        ->and($lines[1]['queries'])->toHaveCount(1);
});
