<?php

namespace App\Filament\Resources\AdCampaigns;

use App\Ads\AdCampaigns;
use App\Ads\AdPlacements;
use App\Ads\AdRejected;
use App\Ads\AdReport;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\AdvertiserSelect;
use App\Filament\Forms\MediaSelect;
use App\Filament\Resources\AdCampaigns\Pages\CampaignReport;
use App\Filament\Resources\AdCampaigns\Pages\CreateAdCampaign;
use App\Filament\Resources\AdCampaigns\Pages\EditAdCampaign;
use App\Filament\Resources\AdCampaigns\Pages\ListAdCampaigns;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ad campaigns (decision 13.3–13.9): who, where, the two pictures, the advertiser's https address, the period, the priority
 * and the status. A campaign shows and disappears by itself at its times; the list says what each one is doing now, with
 * its views, clicks and click rate, and opens its report.
 */
class AdCampaignResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = AdCampaign::class;

    protected static ?string $slug = 'ad-campaigns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $modelLabel = 'حملة إعلانية';

    protected static ?string $pluralModelLabel = 'الحملات الإعلانية';

    protected static ?string $navigationLabel = 'الحملات';

    protected static string|\UnitEnum|null $navigationGroup = 'الإعلانات';

    protected static ?int $navigationSort = 70;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        $placements = AdPlacements::all();
        $flags = AdPlacements::flags();

        return $schema->components([
            Section::make('الحملة')->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name')->label('اسم الحملة')->required()->maxLength(160),
                AdvertiserSelect::make('advertiser_id')->label('المعلن')->required(),
                TextInput::make('target_url')->label('رابط المعلن')->required()->maxLength(2048)->placeholder('https://')
                    ->helperText('يبدأ بـ https:// فقط. الزائر يمرّ على رابط تتبّع يحسب النقرة ثم يصل لهذا الرابط.')->columnSpanFull(),
                Radio::make('status')->label('الحالة')->required()->options(AdCampaign::STATUSES)->inline()
                    ->helperText('«فعّالة» تظهر وحدها من وقت البداية وتختفي عند النهاية. «موقوفة» لا تظهر حتى تعيدها فعّالة.'),
                TextInput::make('priority')->label('الأولوية (1–100)')->required()->integer()->minValue(1)->maxValue(100)
                    ->helperText('عند وجود أكثر من حملة في نفس المكان تظهر كل واحدة بنسبة أولويتها: 80 تظهر ضعف 40.'),
            ]),
            Section::make('المدة (بتوقيت فلسطين)')->columns(['default' => 1, 'md' => 2])->schema([
                DateTimePicker::make('starts_at')->label('البداية')->required()->seconds(false)->native(false)->displayFormat('Y-m-d H:i')->timezone('Asia/Hebron'),
                DateTimePicker::make('ends_at')->label('النهاية')->required()->seconds(false)->native(false)->displayFormat('Y-m-d H:i')->timezone('Asia/Hebron')
                    ->helperText('يظهر تنبيه في الرئيسية قبل النهاية بثلاثة أيام.'),
            ]),
            Section::make('الأماكن والصور')->schema([
                CheckboxList::make('placements')->label('الأماكن')->required()->columns(['default' => 1, 'md' => 2])
                    ->options($placements->mapWithKeys(fn (AdPlacement $p) => [$p->code => $p->name_ar])->all())
                    ->descriptions($placements->mapWithKeys(fn (AdPlacement $p) => [$p->code => self::describe($p, $flags)])->all()),
                MediaSelect::make('desktop_media_id')->label('صورة الكمبيوتر')
                    ->helperText('بنفس نسبة مقاس الكمبيوتر للأماكن المختارة، وبحجمها أو أكبر. من مكتبة الوسائط.'),
                MediaSelect::make('mobile_media_id')->label('صورة الجوال')
                    ->helperText('بنفس نسبة مقاس الجوال للأماكن المختارة، وبحجمها أو أكبر. من مكتبة الوسائط.'),
            ]),
        ]);
    }

    /** A slot's sizes and, when it is off, why. */
    public static function describe(AdPlacement $p, array $flags): string
    {
        $sizes = collect(['desktop' => 'كمبيوتر', 'mobile' => 'جوال'])->map(fn ($label, $device) => ($s = $p->size($device)) ? "{$label} {$s['width']}×{$s['height']}" : null)->filter()->implode('، ');
        $off = match (true) {
            ! $p->is_enabled => ' — المكان مطفأ',
            ! AdPlacements::isOn($p, $flags) => ' — مطفأ بمفتاح التشغيل',
            default => '',
        };

        return $sizes.$off;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('advertiser.organization')->addSelect([
                'ad_campaigns.*',
                'views' => DB::table('ad_stats_daily')->selectRaw('coalesce(sum(impressions), 0)')->whereColumn('campaign_id', 'ad_campaigns.id'),
                'clicks' => DB::table('ad_stats_daily')->selectRaw('coalesce(sum(clicks), 0)')->whereColumn('campaign_id', 'ad_campaigns.id'),
                'slots' => DB::table('ad_campaign_placements')->join('ad_placements', 'ad_placements.code', '=', 'ad_campaign_placements.placement_code')
                    ->selectRaw("string_agg(ad_placements.name_ar, '، ' ORDER BY ad_placements.name_ar)")->whereColumn('campaign_id', 'ad_campaigns.id'),
            ]))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('الحملة')->searchable()->description(fn (AdCampaign $r) => $r->advertiser?->organization?->name)->wrap(),
                TextColumn::make('state')->label('الآن')->badge()
                    ->state(fn (AdCampaign $r) => AdCampaign::STATE_LABELS[$r->state()])
                    ->color(fn (AdCampaign $r) => match ($r->state()) {
                        'running' => 'success', 'scheduled' => 'info', 'paused' => 'warning', default => 'gray'
                    }),
                TextColumn::make('slots')->label('الأماكن')->wrap()->placeholder('—'),
                TextColumn::make('starts_at')->label('من')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('ends_at')->label('إلى')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('priority')->label('الأولوية')->sortable(),
                TextColumn::make('views')->label('مشاهدات')->numeric(),
                TextColumn::make('clicks')->label('نقرات')->numeric(),
                TextColumn::make('ctr')->label('نسبة النقر')->state(fn (AdCampaign $r) => ($c = AdReport::ctr((int) $r->views, (int) $r->clicks)) === null ? '—' : $c.'%'),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة المحفوظة')->options(AdCampaign::STATUSES),
            ])
            ->recordActions([
                Action::make('report')->label('التقرير')->icon(Heroicon::OutlinedChartBar)->url(fn (AdCampaign $r) => CampaignReport::getUrl(['record' => $r])),
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('تُحذف الحملة نهائياً. الحملة التي لها مشاهدات أو نقرات لا تُحذف (أوقفها بدلاً من ذلك).')
                    ->using(function (AdCampaign $record, DeleteAction $action) {
                        try {
                            app(AdCampaigns::class)->delete($record);
                        } catch (AdRejected $e) {
                            Notification::make()->danger()->title('لم تُحذف الحملة')->body($e->getMessage())->send();
                            $action->halt();
                        }

                        return true;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdCampaigns::route('/'),
            'create' => CreateAdCampaign::route('/create'),
            'edit' => EditAdCampaign::route('/{record}/edit'),
            'report' => CampaignReport::route('/{record}/report'),
        ];
    }
}
