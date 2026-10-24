<?php

namespace App\Filament\Pages;

use App\Analytics\StatsExport;
use App\Analytics\StatsReport;
use BackedEnum;
use Filament\Actions\Action;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The statistics (decision 22): visits, sources, devices, interactions and readings for a period, with charts, the
 * other modules' numbers in the same place (22.9) and an Excel export (22.11). The page asks for nothing by itself:
 * it changes only when the admin picks another period (no polling anywhere in the panel).
 *
 * @property-read Schema $form
 */
class Statistics extends Page
{
    protected static ?string $slug = 'statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'الإحصائيات';

    protected static string|\UnitEnum|null $navigationGroup = 'الإحصائيات';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['period' => '7']);
    }

    public function report(): StatsReport
    {
        return StatsReport::for((string) ($this->data['period'] ?? '7'), $this->data['from'] ?? null, $this->data['to'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('تصدير Excel')->icon('heroicon-o-arrow-down-tray')
                ->action(function (): StreamedResponse {
                    $report = $this->report();
                    $bytes = StatsExport::xlsx($report, auth()->id());

                    return response()->streamDownload(fn () => print ($bytes), StatsExport::filename($report),
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                }),
        ];
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
            View::make('filament.statistics')->viewData(fn () => ['r' => $this->report()->data]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'الإحصائيات';
    }
}
