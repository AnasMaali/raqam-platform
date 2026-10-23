<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Newsletter\NewsletterStats;
use App\Newsletter\SubscriberExport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('تصدير Excel')->icon('heroicon-o-arrow-down-tray')
                ->schema([Select::make('which')->label('من')->native(false)->default('active')
                    ->options(['active' => 'المشتركون الحاليون', 'all' => 'الكل (مع من ألغى ومن ارتدّ بريده)'])])
                ->action(function (array $data): StreamedResponse {
                    $bytes = SubscriberExport::xlsx(($data['which'] ?? 'active') === 'all' ? null : ['active'], auth()->id());

                    return response()->streamDownload(fn () => print ($bytes), 'subscribers-'.now('Asia/Hebron')->format('Y-m-d').'.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                }),
        ];
    }

    /** The numbers above the list (19.10): totals and twelve weeks of growth. */
    public function content(Schema $schema): Schema
    {
        $t = NewsletterStats::totals();
        $growth = collect(NewsletterStats::growth())->map(fn ($w) => Text::make('أسبوع '.$w['week'].': +'.$w['joined'].' / −'.$w['left'].' — المجموع '.$w['total']))->all();

        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Section::make('المشتركون')->schema([
                    Text::make('مشتركون الآن: '.$t['active']), Text::make('جدد آخر 7 أيام: '.$t['new_7'].' — آخر 30 يوماً: '.$t['new_30']),
                    Text::make('ألغوا: '.$t['unsubscribed'].' — بريد مرتد: '.$t['bounced'])->color('gray'),
                ]),
                Section::make('النمو أسبوعياً (آخر 12 أسبوعاً)')->collapsible()->collapsed()->schema($growth),
            ]),
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }
}
