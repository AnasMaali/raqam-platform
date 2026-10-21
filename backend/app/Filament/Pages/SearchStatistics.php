<?php

namespace App\Filament\Pages;

use App\Search\SearchStatsReport;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Search statistics (10.14): the most searched words and the words that found nothing, for a period. The words
 * that found nothing tell the editors what to write, or which synonym to add.
 *
 * @property-read Schema $form
 */
class SearchStatistics extends Page
{
    protected static ?string $slug = 'search-statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'إحصائيات البحث';

    protected static string|\UnitEnum|null $navigationGroup = 'البحث';

    protected static ?int $navigationSort = 62;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['period' => '7']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('period')->label('الفترة')->live()->selectablePlaceholder(false)
                ->options(['1' => 'اليوم', '7' => 'آخر 7 أيام', '30' => 'آخر 30 يوماً', 'custom' => 'فترة أخرى']),
            DatePicker::make('from')->label('من')->live()->native(false)->displayFormat('Y-m-d')
                ->maxDate(now('Asia/Hebron'))->visible(fn (Get $get) => $get('period') === 'custom'),
            DatePicker::make('to')->label('إلى')->live()->native(false)->displayFormat('Y-m-d')
                ->maxDate(now('Asia/Hebron'))->visible(fn (Get $get) => $get('period') === 'custom'),
        ])->columns(['default' => 1, 'md' => 3]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form'),
            View::make('filament.search-statistics')->viewData(fn () => ['report' => SearchStatsReport::for(
                (string) ($this->data['period'] ?? '7'), $this->data['from'] ?? null, $this->data['to'] ?? null,
            )]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'إحصائيات البحث';
    }
}
