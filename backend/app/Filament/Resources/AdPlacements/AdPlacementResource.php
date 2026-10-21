<?php

namespace App\Filament\Resources\AdPlacements;

use App\Ads\AdPlacements;
use App\Ads\AdRejected;
use App\Ads\AdSlots;
use App\Filament\Forms\MediaSelect;
use App\Filament\Resources\AdPlacements\Pages\ManageAdPlacements;
use App\Models\AdPlacement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The six ad slots (13.1, 13.2): sizes the site reserves (13.10), on/off, lazy loading (13.11), how often the popup may
 * show, and what an empty slot shows (13.13) with its «أعلن معنا» banner. Slots are edited only, never added or removed.
 */
class AdPlacementResource extends Resource
{
    protected static ?string $model = AdPlacement::class;

    protected static ?string $slug = 'ad-placements';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $modelLabel = 'مكان إعلان';

    protected static ?string $pluralModelLabel = 'أماكن الإعلانات';

    protected static ?string $navigationLabel = 'الأماكن';

    protected static string|\UnitEnum|null $navigationGroup = 'الإعلانات';

    protected static ?int $navigationSort = 71;

    protected static bool $isGloballySearchable = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $flags = AdPlacements::flags();
        $order = "array_position(ARRAY['".implode("','", AdPlacements::ORDER)."']::varchar[], ad_placements.code)";

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('houseAd')->orderByRaw($order)->addSelect([
                'ad_placements.*',
                'running' => DB::table('live_ad_campaigns')->selectRaw('count(*)')->whereColumn('live_ad_campaigns.placement_code', 'ad_placements.code'),
            ]))
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->label('المكان'),
                TextColumn::make('desktop')->label('كمبيوتر')->state(fn (AdPlacement $p) => ($s = $p->size('desktop')) ? "{$s['width']}×{$s['height']}" : '—')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('mobile')->label('جوال')->state(fn (AdPlacement $p) => ($s = $p->size('mobile')) ? "{$s['width']}×{$s['height']}" : '—')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('on')->label('يظهر')->badge()
                    ->state(fn (AdPlacement $p) => AdPlacements::isOn($p, $flags) ? 'يعمل' : ($p->is_enabled ? 'مطفأ بمفتاح التشغيل' : 'مطفأ'))
                    ->color(fn (AdPlacement $p) => AdPlacements::isOn($p, $flags) ? 'success' : 'gray'),
                TextColumn::make('running')->label('حملات تعمل الآن'),
                TextColumn::make('empty_behavior')->label('عندما يكون فارغاً')
                    ->state(fn (AdPlacement $p) => $p->empty_behavior === 'hide' ? 'يختفي' : ($p->houseAd?->is_active ? '«أعلن معنا»' : '«أعلن معنا» (بلا صورة: يختفي)')),
                IconColumn::make('lazy_load')->label('تحميل كسول')->boolean(),
            ])
            ->recordActions([self::editAction()]);
    }

    private static function editAction(): Action
    {
        $device = fn (string $d, string $label) => [
            TextInput::make("{$d}_width")->label("عرض {$label}")->integer()->suffix('px'),
            TextInput::make("{$d}_height")->label("ارتفاع {$label}")->integer()->suffix('px'),
        ];

        return Action::make('edit')->label('تعديل')->icon(Heroicon::OutlinedPencilSquare)->modalSubmitActionLabel('حفظ')
            ->modalHeading(fn (AdPlacement $p) => 'تعديل: '.$p->name_ar)
            ->fillForm(fn (AdPlacement $p) => $p->only(['name_ar', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height', 'is_enabled', 'empty_behavior', 'frequency_cap_hours', 'lazy_load']) + [
                'house_desktop_media_id' => $p->houseAd?->desktop_media_id, 'house_mobile_media_id' => $p->houseAd?->mobile_media_id,
                'house_target_path' => $p->houseAd?->target_path ?? '/advertise', 'house_is_active' => $p->houseAd?->is_active ?? true,
            ])
            ->schema(fn (AdPlacement $p) => [
                TextInput::make('name_ar')->label('الاسم')->required()->maxLength(60),
                Section::make('المقاس المحجوز')->description('الموقع يحجز هذا المقاس قبل تحميل الإعلان، فلا تقفز الصفحة.')->columns(['default' => 2, 'md' => 4])
                    ->schema($p->code === 'mobile_sticky' ? $device('mobile', 'الجوال') : [...$device('desktop', 'الكمبيوتر'), ...$device('mobile', 'الجوال')]),
                Toggle::make('is_enabled')->label('المكان يعمل'),
                ...(isset(AdPlacements::SWITCHES[$p->code]) ? [Text::make('ويحتاج أيضاً تفعيل مفتاح التشغيل «'.DB::table('feature_flags')->where('key', AdPlacements::SWITCHES[$p->code])->value('description_ar').'» (الإعدادات ← مفاتيح التشغيل). مطفأ افتراضياً.')->color('warning')] : []),
                ...($p->code === 'popup' ? [TextInput::make('frequency_cap_hours')->label('تظهر للزائر مرة كل (ساعة)')->integer()->required()->helperText('من 1 إلى 720. يتذكرها متصفح الزائر بدون كوكيز.')] : []),
                Toggle::make('lazy_load')->label('تحميل كسول')->helperText('الصورة تُحمّل عند الاقتراب منها. أعلى الصفحة وشريط الجوال يظهران فوراً فالأفضل إطفاؤه لهما.'),
                Radio::make('empty_behavior')->label('عندما لا توجد حملة')->required()->options(['house' => 'إعلان «أعلن معنا»', 'hide' => 'إخفاء المكان']),
                Section::make('إعلان «أعلن معنا»')->description('يظهر فقط إذا اخترت «أعلن معنا» ولا توجد حملة. بدون صورة: يختفي المكان.')->schema([
                    MediaSelect::make('house_desktop_media_id')->label('صورة الكمبيوتر')->visible($p->code !== 'mobile_sticky'),
                    MediaSelect::make('house_mobile_media_id')->label('صورة الجوال'),
                    TextInput::make('house_target_path')->label('الرابط')->maxLength(500)->helperText('مسار في الموقع مثل /advertise، أو رابط يبدأ بـ https://'),
                    Toggle::make('house_is_active')->label('مفعّل'),
                ]),
            ])
            ->action(function (array $data, AdPlacement $record, Action $action) {
                try {
                    app(AdSlots::class)->save($record, $data);
                } catch (AdRejected $e) {
                    // A dialog that throws a validation error stays open without a word; say it and keep it open.
                    Notification::make()->danger()->persistent()->title('لم يُحفظ المكان')->body($e->getMessage())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('حُفظ المكان.')->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdPlacements::route('/')];
    }
}
