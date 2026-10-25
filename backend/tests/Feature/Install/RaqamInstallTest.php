<?php

use App\Access\PermissionCatalog;
use App\Console\InstallServicesCommand;
use App\Console\RaqamInstallCommand;
use App\Content\StaticPages;
use App\Services\ServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** The rows every install step writes, as text, so a second run can be compared row by row. */
function installedRows(): array
{
    $tables = ['permissions', 'pages', 'characters', 'cities', 'weather_locations', 'services', 'service_deliverables', 'email_templates',
        'synonym_groups', 'synonym_terms', 'bot_entries', 'bot_entry_variants', 'bot_entry_keywords', 'bot_quick_replies', 'bot_entry_quick_replies', 'home_blocks'];

    return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::selectOne("SELECT count(*) AS n, md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) AS h FROM {$t} t")])
        ->map(fn ($r) => [(int) $r->n, $r->h])->all();
}

test('raqam:install runs the migrations and every install step in order on an empty installation', function () {
    $this->artisan('raqam:install')->assertSuccessful()
        ->expectsOutputToContain('→ permissions:install')->expectsOutputToContain('→ home:install')
        ->expectsOutputToContain('skipped: search:setup (no search engine key)')->expectsOutputToContain('Installation complete.');

    $rows = installedRows();
    expect($rows['permissions'][0])->toBe(count(PermissionCatalog::PERMISSIONS))
        ->and($rows['pages'][0])->toBe(count(StaticPages::SYSTEM))
        ->and($rows['characters'][0])->toBe(8)->and($rows['cities'][0])->toBe(12)->and($rows['services'][0])->toBe(6)
        ->and($rows['email_templates'][0])->toBeGreaterThan(0)->and($rows['synonym_groups'][0])->toBeGreaterThan(0)
        ->and($rows['bot_quick_replies'][0])->toBe(5)->and($rows['home_blocks'][0])->toBeGreaterThan(0);
});

test('running it again changes nothing at all', function () {
    $this->artisan('raqam:install')->assertSuccessful();
    $first = installedRows();

    $this->artisan('raqam:install')->assertSuccessful();

    expect(installedRows())->toBe($first);
});

test('it leaves alone what the admin changed since', function () {
    $this->artisan('raqam:install', ['--no-migrate' => true])->assertSuccessful();
    DB::table('characters')->where('slug', DB::table('characters')->orderBy('id')->value('slug'))->update(['name_ar' => 'اسم من الأدمن']);
    DB::table('pages')->where('system_key', 'about')->update(['title' => 'عن رَقَم']);

    $this->artisan('raqam:install', ['--no-migrate' => true])->assertSuccessful();

    expect(DB::table('characters')->where('name_ar', 'اسم من الأدمن')->count())->toBe(1)
        ->and(DB::table('pages')->where('system_key', 'about')->value('title'))->toBe('عن رَقَم');
});

test('the steps run in the order written, and every install command of the application is one of them', function () {
    $order = [];
    foreach (array_keys(RaqamInstallCommand::STEPS) as $command) {
        $order[] = $command;
    }
    expect($order[0])->toBe('permissions:install')->and(array_search('bot:install', $order))->toBeGreaterThan(array_search('pages:install', $order))
        ->and(array_search('bot:install', $order))->toBeGreaterThan(array_search('services:install', $order));

    // The application's own install commands (Laravel's and packages' ones, such as migrate:install, are not ours).
    $installs = collect(Artisan::all())->filter(fn ($command, $name) => str_ends_with($name, ':install') && $name !== 'raqam:install' && str_starts_with($command::class, 'App\\'))
        ->keys()->sort()->values()->all();
    expect($installs)->toBe(collect(array_keys(RaqamInstallCommand::STEPS))->sort()->values()->all());
});

test('a step that fails stops the run and says which one', function () {
    $this->app->instance(InstallServicesCommand::class, new class extends InstallServicesCommand
    {
        public function handle(ServiceCatalog $catalog): int
        {
            return self::FAILURE;
        }
    });

    $this->artisan('raqam:install', ['--no-migrate' => true])->assertFailed()
        ->expectsOutputToContain('services:install failed; the steps after it were not run');
    expect(DB::table('home_blocks')->count())->toBe(0);   // home:install comes after it and never ran
});
