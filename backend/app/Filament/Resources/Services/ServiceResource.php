<?php

namespace App\Filament\Resources\Services;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Service;
use App\Services\ServiceBoard;
use App\Support\ArabicSlug;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Services and prices (decision 14): what each service is and includes, its picture, its price (fixed, "from" or on
 * request) in shekels or dollars with a note, an optional special offer that ends by itself, its own FAQ, and where its
 * published examples come from. Every price change is in the audit log from → to.
 */
class ServiceResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Service::class;

    protected static ?string $slug = 'services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'خدمة';

    protected static ?string $pluralModelLabel = 'الخدمات والأسعار';

    protected static ?string $navigationLabel = 'الخدمات والأسعار';

    protected static string|\UnitEnum|null $navigationGroup = 'الخدمات';

    protected static ?int $navigationSort = 75;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الخدمة')->columns(['default' => 1, 'md' => 2])->schema([
                Select::make('group_id')->label('المجموعة')->required()->native(false)->options(fn () => DB::table('service_groups')->orderBy('sort_order')->pluck('name_ar', 'id')->all()),
                TextInput::make('name_ar')->label('اسم الخدمة')->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
                TextInput::make('slug')->label('الرابط')->maxLength(200)->helperText('صفحة الخدمة: /services/الرابط. يُنشأ من الاسم تلقائياً.'),
                Toggle::make('is_active')->label('ظاهرة على الموقع'),
                Textarea::make('summary')->label('وصف قصير')->required()->maxLength(300)->rows(2)->columnSpanFull()->helperText('يظهر على بطاقة الخدمة في صفحة الأسعار.'),
                RichEditor::make('description')->label('الوصف الكامل')->columnSpanFull()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
                MediaSelect::make('cover_media_id')->label('الصورة'),
                Select::make('section_id')->label('أمثلة من قسم')->native(false)->placeholder('بدون أمثلة')
                    ->options(fn () => DB::table('sections')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                    ->helperText('تعرض صفحة الخدمة أحدث المواد المنشورة من هذا القسم كأمثلة.'),
            ]),
            Section::make('ماذا تشمل')->schema([
                Repeater::make('deliverables')->hiddenLabel()->simple(TextInput::make('label')->required()->maxLength(200))
                    ->reorderable()->maxItems(20)->addActionLabel('إضافة بند')->defaultItems(0),
            ]),
            Section::make('السعر')->columns(['default' => 1, 'md' => 3])->schema([
                Radio::make('price_mode')->label('طريقة العرض')->required()->options(Service::MODES)->live()->columnSpanFull(),
                TextInput::make('price')->label('المبلغ')->placeholder('150 أو 16.12')->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'decimal'])
                    ->helperText('بالأرقام فقط، ومنزلتان عشريتان على الأكثر.')->visible(fn (Get $get) => self::priced($get))->required(fn (Get $get) => self::priced($get)),
                Select::make('currency_code')->label('العملة')->native(false)->options(Service::CURRENCIES)->visible(fn (Get $get) => self::priced($get))->required(fn (Get $get) => self::priced($get)),
                TextInput::make('price_note')->label('ملاحظة')->placeholder('للحلقة، للشهر…')->maxLength(80)->visible(fn (Get $get) => self::priced($get)),
                Toggle::make('offer')->label('عرض خاص')->live()->visible(fn (Get $get) => self::priced($get))->columnSpanFull()
                    ->helperText(fn (Get $get) => $get('offer_expired') ? 'العرض السابق انتهى في '.$get('offer_expired').' ورجع السعر العادي.' : 'سعر أقل حتى وقت تختاره، ثم يرجع السعر العادي وحده.'),
                TextInput::make('sale_price')->label('سعر العرض')->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'decimal'])
                    ->visible(fn (Get $get) => self::priced($get) && $get('offer'))->required(fn (Get $get) => self::priced($get) && $get('offer')),
                DateTimePicker::make('sale_ends_at')->label('ينتهي العرض (توقيت فلسطين)')->seconds(false)->native(false)->displayFormat('Y-m-d H:i')->timezone('Asia/Hebron')
                    ->visible(fn (Get $get) => self::priced($get) && $get('offer'))->required(fn (Get $get) => self::priced($get) && $get('offer')),
            ]),
            Section::make('أسئلة شائعة خاصة بالخدمة')->schema([
                Repeater::make('faqs')->hiddenLabel()->schema([
                    TextInput::make('question')->label('السؤال')->required()->maxLength(300),
                    Textarea::make('answer')->label('الجواب')->required()->maxLength(3000)->rows(3),
                    Toggle::make('is_published')->label('ظاهر')->default(true),
                ])->reorderable()->maxItems(20)->addActionLabel('إضافة سؤال')->defaultItems(0)->collapsible(),
            ]),
            SeoSection::make('اتركها فارغة لاستعمال اسم الخدمة ووصفها القصير.'),
        ]);
    }

    private static function priced(Get $get): bool
    {
        return in_array($get('price_mode'), ['fixed', 'from'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('group')->addSelect([
                'services.*',
                'presses' => DB::table('service_contact_clicks_daily')->selectRaw('coalesce(sum(clicks), 0)')->whereColumn('service_id', 'services.id')
                    ->whereRaw("stat_date > (now() AT TIME ZONE 'Asia/Hebron')::date - 30"),
            ]))
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label('الخدمة')->searchable()->description(fn (Service $s) => $s->group?->name_ar),
                TextColumn::make('price')->label('السعر')->state(fn (Service $s) => ServiceBoard::price($s)['text'])
                    ->description(fn (Service $s) => ($o = ServiceBoard::price($s)['offer']) ? 'عرض خاص؛ كان '.$o['was_text'] : null),
                IconColumn::make('is_active')->label('ظاهرة')->boolean(),
                TextColumn::make('presses')->label('ضغطات التواصل (30 يوماً)')->numeric(),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i', 'Asia/Hebron'),
            ])
            ->filters([
                SelectFilter::make('group_id')->label('المجموعة')->options(fn () => DB::table('service_groups')->orderBy('sort_order')->pluck('name_ar', 'id')->all()),
                TrashedFilter::make()->label('المحذوفة'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('تختفي الخدمة من الموقع وتنتقل للمحذوفات، ويمكن استرجاعها.')
                    ->after(fn (Service $record) => ServiceBoard::changed($record->slug)),
                RestoreAction::make()->label('استرجاع')->after(fn (Service $record) => ServiceBoard::changed($record->slug)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
