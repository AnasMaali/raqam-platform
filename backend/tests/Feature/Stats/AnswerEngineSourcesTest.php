<?php

use App\Analytics\AnswerEngineSources;
use App\Analytics\StatsKeys;
use App\Analytics\StatsReport;
use App\Analytics\TrafficSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | The answer engines as sources of visits (phase 10b, GEO). This file and the data files it tests are the only places the
 | engines are named (decision 2026-10-25).
 */

beforeEach(function () {
    config(['raqam.api.site_token' => STATS_SITE_TOKEN, 'raqam.site_url' => 'https://raqam.example']);
    clearStatsKeys();
});

afterEach(fn () => clearStatsKeys());

test('every answer engine is a seeded source with an Arabic name', function () {
    $seeded = DB::table('traffic_sources')->pluck('name_ar', 'code');

    foreach (array_keys(AnswerEngineSources::HOSTS) as $code) {
        expect($seeded[$code] ?? null)->not->toBeNull($code);
    }
    expect(array_keys(AnswerEngineSources::HOSTS))->toBe(['chatgpt', 'perplexity', 'gemini', 'copilot', 'claude'])
        ->and(array_unique(array_values(AnswerEngineSources::TAGS + AnswerEngineSources::APPS)))->each->toBeIn(array_keys(AnswerEngineSources::HOSTS));
});

test('a visit from an answer engine is told by its referrer, its campaign tag or its app', function (?string $referrer, string $path, string $source) {
    expect(TrafficSource::of($referrer, $path, 'raqam.example'))->toBe($source);
})->with([
    'chatgpt referrer' => ['https://chatgpt.com/', '/news/1/x', 'chatgpt'],
    'old chatgpt host' => ['https://chat.openai.com/c/abc', '/', 'chatgpt'],
    'chatgpt tag' => [null, '/news/1/x?utm_source=chatgpt.com', 'chatgpt'],
    'chatgpt app' => ['android-app://com.openai.chatgpt/', '/', 'chatgpt'],
    'perplexity referrer' => ['https://www.perplexity.ai/search?q=x', '/', 'perplexity'],
    'perplexity tag' => [null, '/?utm_source=perplexity', 'perplexity'],
    'perplexity app' => ['android-app://ai.perplexity.app.android', '/', 'perplexity'],
    'gemini, a subdomain of google' => ['https://gemini.google.com/', '/', 'gemini'],
    'gemini app' => ['android-app://com.google.android.apps.bard', '/', 'gemini'],
    'copilot referrer' => ['https://copilot.microsoft.com/', '/', 'copilot'],
    'copilot tag' => [null, '/?utm_source=copilot', 'copilot'],
    'claude referrer' => ['https://claude.ai/chat/1', '/', 'claude'],
    'claude tag' => [null, '/?utm_source=claude.ai', 'claude'],
    'claude app' => ['android-app://com.anthropic.claude', '/', 'claude'],
    // what must not change
    'google search stays google' => ['https://www.google.com/', '/', 'google'],
    'google news stays google' => ['https://news.google.com/', '/', 'google'],
    'bing stays bing' => ['https://www.bing.com/search?q=x', '/', 'bing'],
    'a look-alike host is another site' => ['https://notchatgpt.com/', '/', 'other'],
    'a look-alike suffix is another site' => ['https://claude.ai.evil.example/', '/', 'other'],
]);

test('visits from answer engines reach the daily table and the statistics screen', function () {
    siteHit(['type' => 'view', 'path' => '/', 'referrer' => 'https://chatgpt.com/']);
    siteHit(['type' => 'view', 'path' => '/a?utm_source=perplexity'], ip: '198.51.100.9');
    siteHit(['type' => 'view', 'path' => '/b', 'referrer' => 'https://gemini.google.com/'], ip: '198.51.100.10');

    $this->artisan('stats:flush')->assertSuccessful();

    expect(DB::table('traffic_sources_daily')->where('stat_date', StatsKeys::today())->orderBy('source_code')->pluck('visits', 'source_code')->map(fn ($v) => (int) $v)->all())
        ->toBe(['chatgpt' => 1, 'gemini' => 1, 'perplexity' => 1]);
    expect(json_encode(StatsReport::for('today')->data['sources'], JSON_UNESCAPED_UNICODE))->toContain('شات جي بي تي');
});
