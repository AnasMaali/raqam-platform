<?php

use App\Filament\Forms\LtrFields;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Media\ImageProcessor;
use App\Models\User;
use App\Panel\PanelAlerts;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** ImageProcessor remembers its probe per process; the test sets the remembered answer. */
function avifWorks(bool $works): void
{
    $probed = new ReflectionProperty(ImageProcessor::class, 'probed');
    $probed->setValue(null, ['avif' => $works]);
}

afterEach(fn () => (new ReflectionProperty(ImageProcessor::class, 'probed'))->setValue(null, []));

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
});

test('fields with Latin content are written left to right', function (TextInput $input, bool $expected) {
    expect(LtrFields::applies($input))->toBe($expected);
})->with([
    'email type' => [fn () => TextInput::make('anything')->email(), true],
    'url type' => [fn () => TextInput::make('anything')->url(), true],
    'tel type' => [fn () => TextInput::make('anything')->tel(), true],
    'slug' => [fn () => TextInput::make('slug'), true],
    'sign-in code' => [fn () => TextInput::make('code'), true],
    'social link' => [fn () => TextInput::make('links.facebook'), true],
    'youtube address' => [fn () => TextInput::make('youtube_url'), true],
    'a title stays right to left' => [fn () => TextInput::make('title'), false],
    'a name stays right to left' => [fn () => TextInput::make('name_ar'), false],
]);

test('the rendered guest form puts a slug and a link left to right and a name right to left', function () {
    $html = Livewire::test(CreateGuest::class)->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $directions = [];
    foreach ($dom->getElementsByTagName('input') as $input) {
        foreach ($input->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'wire:model')) {
                $directions[$attribute->value] = $input->getAttribute('dir');
            }
        }
    }

    expect($directions['data.slug'])->toBe('ltr')
        ->and($directions['data.links.facebook'])->toBe('ltr')
        ->and($directions['data.name'])->toBe('');
});

test('the home warns when production cannot write AVIF, and only then', function () {
    avifWorks(false);

    expect(PanelAlerts::all())->toBe([]); // not production: a development machine may lack AVIF

    app()->detectEnvironment(fn () => 'production');
    expect(PanelAlerts::all())->toHaveCount(1)->and(PanelAlerts::all()[0])->toContain('AVIF');

    Livewire::test(Dashboard::class)->assertSee('libavif');
});

test('no warning when AVIF can be written', function () {
    avifWorks(true);
    app()->detectEnvironment(fn () => 'production');

    expect(PanelAlerts::all())->toBe([]);
});

test('the test run writes to its own log, never the development one', function () {
    expect(config('logging.channels.single.path'))->toEndWith('/storage/logs/testing.log');
});
