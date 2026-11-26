<?php

namespace App\Filament\Pages;

use App\Ads\AdAlerts;
use App\Analytics\StatsReport;
use App\Filament\Resources\AdminNotifications\AdminNotificationResource;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\Posts\PostResource;
use App\Jobs\SendSiteWebhook;
use App\Models\ContactMessage;
use App\Models\Post;
use App\Panel\AdminAlerts;
use App\Panel\ConnectionStatus;
use App\Panel\PanelAlerts;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The panel's home (21.4), the editor's first (panel phase 3): what needs acting on, the drafts in progress, what goes live
 * next and the messages waiting; then the day's alerts, visits, most read and campaigns; the system's state last, closed,
 * and only for whoever holds «settings.manage».
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'الرئيسية';

    protected static ?string $navigationLabel = 'الرئيسية';

    protected function getHeaderActions(): array
    {
        return [
            // The editor's first move from the home: a new item. The one primary button of the page.
            Action::make('newPost')->label('مادة جديدة')->icon('heroicon-o-plus')->color('primary')->url(PostResource::getUrl('create')),
            Action::make('refreshSite')->label('تحديث الموقع الآن')->icon('heroicon-o-arrow-path')->color('gray')->requiresConfirmation()
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
        $alerts = array_map(fn (string $alert) => Section::make('تنبيه')->icon('heroicon-o-exclamation-triangle')->iconColor('warning')->schema([Text::make($alert)]),
            PanelAlerts::editorial());
        $userId = (int) Filament::auth()->id();
        // A date and time inside an Arabic line, isolated as one left-to-right run so its parts keep their order, and held
        // together (non-breaking hyphens and space) so a phone does not cut it at a hyphen.
        $ltr = fn (string $text) => "\u{2066}".str_replace(['-', ' '], ["\u{2011}", "\u{00A0}"], $text)."\u{2069}";
        $at = fn ($time) => $ltr(Carbon::parse($time)->setTimezone('Asia/Hebron')->format('Y-m-d H:i'));

        // The editor's work first (panel phase 3): the drafts in progress, what goes live next, the messages waiting.
        $drafts = Post::query()->where('status', 'draft')->orderByDesc('updated_at')->limit(5)->get(['id', 'title', 'updated_at']);
        $scheduled = Post::query()->where('status', 'scheduled')->orderBy('published_at')->limit(5)->get(['id', 'title', 'published_at']);
        $messages = ContactMessage::query()->where('status', 'new')->orderByDesc('created_at')->limit(5)->get(['id', 'name', 'subject', 'created_at']);
        $newCount = ContactMessage::query()->where('status', 'new')->count();
        $work = [
            self::list('مسودات قيد العمل', 'لا مسودات الآن.', $drafts->map(fn (Post $p) => ['label' => $p->title, 'meta' => 'عُدّلت '.$at($p->updated_at), 'url' => PostResource::getUrl('edit', ['record' => $p])])->all()),
            self::list('يُنشر قريباً', 'لا مواد مجدولة.', $scheduled->map(fn (Post $p) => ['label' => $p->title, 'meta' => 'يُنشر '.$at($p->published_at), 'url' => PostResource::getUrl('edit', ['record' => $p])])->all()),
            self::list('رسائل جديدة ('.$newCount.')', 'لا رسائل جديدة.', $messages->map(fn (ContactMessage $m) => ['label' => $m->subject, 'meta' => $m->name, 'url' => ContactMessageResource::getUrl('view', ['record' => $m])])->all()),
        ];

        // Then the day: the unread alerts, today's visits (live) and the most read today (22), the campaigns showing now (21.4).
        $unread = array_values(array_filter(AdminAlerts::latest($userId, 5), fn ($n) => $n->read_at === null));
        $today = StatsReport::for('1')->data;
        $running = AdAlerts::running();
        $day = [
            self::list('التنبيهات ('.AdminAlerts::unreadCount($userId).' غير مقروءة)', 'لا تنبيهات جديدة.', array_map(fn ($n) => ['label' => $n->title, 'meta' => null, 'url' => AdminNotificationResource::getUrl()], $unread)),
            Section::make('زيارات اليوم')->schema([
                Text::make('مشاهدات الصفحات: '.number_format($today['totals']['page_views'])),
                Text::make('الزوار: '.number_format($today['totals']['visitors']).' — الزيارات: '.number_format($today['totals']['visits'])),
                Text::make('التفاصيل في «الإحصائيات».')->color('gray'),
            ]),
            self::list('الأكثر قراءة اليوم', 'لا قراءات بعد اليوم.', array_map(fn (array $p) => ['label' => $p['title'], 'meta' => number_format($p['count']).' قراءة', 'url' => PostResource::getUrl('edit', ['record' => $p['id']])], array_slice($today['posts'], 0, 5))),
            self::list('حملات إعلانية فعّالة ('.count($running).')', 'لا توجد حملة تعمل الآن.', array_map(fn (array $c) => ['label' => $c['name'], 'meta' => $c['advertiser'].' — حتى '.$ltr($c['ends_at']), 'url' => null], array_slice($running, 0, 8))),
        ];

        return $schema->components([
            ...$alerts,
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($work),
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema($day),
            ...(Gate::allows('settings.manage') ? [self::system()] : []),
        ]);
    }

    /**
     * The system's state (21.15), lower and closed, and only for whoever holds «settings.manage»: the server's warnings,
     * and every outside connection with its key's name, shown only as set or not set.
     */
    private static function system(): Section
    {
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
        $warnings = array_map(fn (string $alert) => Text::make($alert), PanelAlerts::system());

        return Section::make('حالة النظام')->description('الربط الخارجي ومفاتيح التشغيل. تظهر لمن يملك صلاحية الإعدادات.')
            ->icon('heroicon-o-server-stack')->collapsible()->collapsed()->extraAttributes(['data-system' => true])
            ->schema([...$warnings, Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($connections)]);
    }

    /**
     * A card of the home: a short list, each item a link to its screen with a quiet second line.
     *
     * @param  list<array{label: string, meta: ?string, url: ?string}>  $items
     */
    private static function list(string $heading, string $empty, array $items): Section
    {
        return Section::make($heading)->schema([View::make('filament.dashboard-list')->viewData(['items' => $items, 'empty' => $empty])]);
    }
}
