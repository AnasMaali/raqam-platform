<?php

namespace App\Filament\Resources\AdCampaigns\Pages;

use App\Ads\AdRejected;
use App\Ads\AdReport;
use App\Ads\AdReportExport;
use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use App\Models\AdCampaign;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A campaign's report for any period (13.8): views, clicks and click rate per day and per slot, with an Excel and a PDF
 * copy of exactly what is on screen.
 *
 * @property-read Schema $form
 */
class CampaignReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AdCampaignResource::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        [$from, $to] = AdReport::defaultPeriod($this->campaign());
        $this->form->fill(['from' => $from, 'to' => $to]);
    }

    private function campaign(): AdCampaign
    {
        /** @var AdCampaign */
        return $this->getRecord();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            DatePicker::make('from')->label('من')->live()->native(false)->displayFormat('Y-m-d')->required(),
            DatePicker::make('to')->label('إلى')->live()->native(false)->displayFormat('Y-m-d')->required(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    /** @return array{0: ?array, 1: ?string} the report, or why there is none */
    private function report(): array
    {
        try {
            // The picker may hold a date with a midnight time ("2026-10-21 00:00:00"): the day is the first ten characters.
            $day = fn (string $key) => substr((string) ($this->data[$key] ?? ''), 0, 10);

            return [app(AdReport::class)->build($this->campaign(), $day('from'), $day('to')), null];
        } catch (AdRejected $e) {
            return [null, $e->getMessage()];
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form'),
            View::make('filament.ad-report')->viewData(function () {
                [$report, $problem] = $this->report();

                return ['report' => $report, 'problem' => $problem];
            }),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('xlsx')->label('تصدير Excel')->icon('heroicon-o-table-cells')->action(fn () => $this->download('xlsx')),
            Action::make('pdf')->label('تصدير PDF')->icon('heroicon-o-document-arrow-down')->action(fn () => $this->download('pdf')),
        ];
    }

    private function download(string $format): ?StreamedResponse
    {
        [$report, $problem] = $this->report();
        if ($report === null) {
            Notification::make()->danger()->title($problem)->send();

            return null;
        }
        $bytes = $format === 'pdf' ? app(AdReportExport::class)->pdf($report) : app(AdReportExport::class)->xlsx($report);
        $type = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, AdReportExport::filename($report, $format), ['Content-Type' => $type]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'تقرير: '.$this->campaign()->name;
    }
}
