<?php

namespace App\Filament\Pages;

use App\Jobs\SendSiteWebhook;
use App\Panel\ConnectionStatus;
use App\Panel\PanelAlerts;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * The panel's home (21.4): the shape only. Each card says which phase fills it with real numbers.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'الرئيسية';

    protected static ?string $navigationLabel = 'الرئيسية';

    /** @var array<string, string> card title => where its numbers come from */
    private const CARDS = [
        'زيارات اليوم' => 'تظهر مع مرحلة الإحصائيات.',
        'الأكثر قراءة' => 'تظهر مع مرحلة الإحصائيات.',
        'رسائل جديدة' => 'تظهر مع نموذج «اتصل بنا».',
        'حملات إعلانية فعّالة' => 'تظهر مع مرحلة الإعلانات.',
        'التنبيهات' => 'تظهر مع مرحلة التنبيهات.',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshSite')->label('تحديث الموقع الآن')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                ->modalDescription('يُبلَّغ الموقع بتحديث كل صفحاته من آخر محتوى.')
                ->action(function () {
                    if (! SendSiteWebhook::configured()) {
                        Notification::make()->warning()->title('رابط تحديث الموقع غير مضبوط بعد.')->send();

                        return;
                    }
                    SendSiteWebhook::notify(['type' => 'all', 'change' => 'refresh', 'paths' => []]);
                    Notification::make()->success()->title('أُرسل طلب التحديث.')->send();
                }),
        ];
    }

    public function getWidgets(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        $alerts = [];
        foreach (PanelAlerts::all() as $alert) {
            $alerts[] = Section::make('تنبيه')->icon('heroicon-o-exclamation-triangle')->iconColor('warning')->schema([Text::make($alert)]);
        }

        $cards = [];
        foreach (self::CARDS as $title => $note) {
            $cards[] = Section::make($title)->schema([Text::make($note)->color('gray')]);
        }

        // 21.15: every outside connection, keys shown only as set or not set.
        $connections = [];
        foreach (ConnectionStatus::all() as $c) {
            [$icon, $color, $label] = match ($c['state']) {
                ConnectionStatus::OK => ['heroicon-o-check-circle', 'success', 'يعمل'],
                ConnectionStatus::FAILING => ['heroicon-o-x-circle', 'danger', 'لا يستجيب'],
                ConnectionStatus::OFF => ['heroicon-o-minus-circle', 'gray', 'غير مفعّل'],
                default => ['heroicon-o-question-mark-circle', 'warning', 'غير معروف بعد'],
            };
            $connections[] = Section::make($c['name'].' — '.$label)->icon($icon)->iconColor($color)->compact()
                ->schema([Text::make('المفتاح: '.$c['key'])->color('gray'), ...array_map(fn (string $line) => Text::make($line), $c['lines'])]);
        }

        return $schema->components([
            ...$alerts,
            Section::make('حالة الربط الخارجي')->schema([Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($connections)]),
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($cards),
        ]);
    }
}
