<?php

use Illuminate\Support\Facades\Process;

/**
 * Runs the dialog's real script (the x-data the page renders) in Node against a fake clock, a fake
 * server and fake tabs. Skipped on a machine without Node.
 */
test('the warning dialog behaves: it asks before leaving, never extends alone, and tabs stay in step', function () {
    if (! trim((string) shell_exec('command -v node'))) {
        $this->markTestSkipped('Node is not installed.');
    }

    $html = view('filament.session-timeout', [
        'remaining' => 1800, 'cap' => 43200, 'idle' => 1800, 'warn' => 120,
        'keepAliveUrl' => '/p/session/keep-alive', 'statusUrl' => '/p/session/status', 'loginUrl' => '/p/login?expired=1',
    ])->render();

    preg_match('/x-data="(\{.*?\n    \})"\n    x-show/s', $html, $match);
    expect($match)->not->toBeEmpty();

    $file = tempnam(sys_get_temp_dir(), 'dialog').'.js';
    file_put_contents($file, '('.html_entity_decode($match[1]).')');

    $result = Process::run(['node', base_path('tests/Support/session-dialog.sim.cjs'), $file]);
    @unlink($file);

    expect($result->output())->not->toContain('FAIL')->and($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    expect(substr_count($result->output(), 'ok   '))->toBeGreaterThanOrEqual(20);
});
