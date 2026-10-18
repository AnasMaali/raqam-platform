<?php

use App\Content\PostSaver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * Runs the editor's real script (the x-data the page renders) in Node with a fake clock, a fake localStorage and a
 * fake Livewire component: the 30-second autosave, the browser copy, and getting it back. Skipped without Node.
 */
test('autosave, the browser copy and its restore behave', function () {
    if (! trim((string) shell_exec('command -v node'))) {
        $this->markTestSkipped('Node is not installed.');
    }

    $html = view('filament.post-editor', ['key' => 'raqam:post:1:42', 'serverAt' => 1_699_999_000, 'isDraft' => true])->render();
    preg_match('/x-data="(\{.*?\n    \})"\n    x-cloak/s', $html, $match);
    expect($match)->not->toBeEmpty();

    // The script sits inside an HTML attribute: a stray double quote would cut it short and kill it in the browser.
    expect(html_entity_decode($match[1]))->not->toContain('"');

    $file = tempnam(sys_get_temp_dir(), 'editor').'.js';
    file_put_contents($file, '('.html_entity_decode($match[1]).')');

    $result = Process::run(['node', base_path('tests/Support/post-editor.sim.cjs'), $file]);
    @unlink($file);

    expect($result->output())->not->toContain('FAIL')->and($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    expect(substr_count($result->output(), 'ok   '))->toBeGreaterThanOrEqual(15);
});

test('the editor page carries the script, with the key of this user and this post only', function () {
    ensurePostKinds();
    $admin = User::factory()->staff()->create();
    $post = app(PostSaver::class)->create('news', 'مسودة', $admin->id);

    $this->actingAs($admin)->get('/'.config('raqam.admin_path')."/posts/{$post->id}/edit")->assertOk()
        ->assertSee("raqam:post:{$admin->id}:{$post->id}", false)->assertSee('post-autosaved', false);

    $this->get('/'.config('raqam.admin_path').'/posts')->assertOk()->assertDontSee('post-autosaved', false);
});

test('the script is not on other pages', function () {
    $admin = User::factory()->staff()->create();

    $this->actingAs($admin)->followingRedirects()->get('/'.config('raqam.admin_path'))->assertOk()->assertDontSee('post-autosaved', false);
});
