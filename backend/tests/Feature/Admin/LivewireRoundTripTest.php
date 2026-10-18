<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * What a browser does and Livewire::test() does not: render the page in one request, then post its
 * snapshot back to /livewire/update in another, where the component is found again by its name.
 */
function livewireSnapshotOf(string $html): array
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $found);

    return json_decode(html_entity_decode($found[1]), true);
}

test('the code page answers the request its own button sends', function () {
    Filament::setCurrentPanel('admin');
    $this->withSession(['admin_sign_in' => [
        'email' => 'nobody@example.test', 'binding' => bin2hex(random_bytes(8)), 'user_id' => null,
        'started_at' => now()->getTimestamp(), 'failures' => 0,
    ]]);

    $page = $this->get('/'.config('raqam.admin_path').'/login/code')->assertOk();

    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => json_encode(livewireSnapshotOf($page->getContent())),
        'updates' => ['data.code' => '123456'],
        'calls' => [['path' => '', 'method' => 'verify', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk();
});
