<?php

namespace App\Filament\Resources\Characters;

use App\Awareness\CharacterLibrary;
use App\Awareness\ContrastCheck;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\RiveSelect;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Characters\Pages\CreateCharacter;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Models\Character;
use App\Support\ArabicSlug;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** The awareness characters (decisions 7.1 – 7.14): name, role, colour, and a still picture (with an optional animation state) for each of five poses. */
class CharacterResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Character::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static ?string $modelLabel = 'شخصية';

    protected static ?string $pluralModelLabel = 'الشخصيات';

    protected static ?string $navigationLabel = 'شخصيات التوعية';

    protected static ?int $navigationSort = 27;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        $poses = [];
        foreach (DB::table('pose_types')->orderBy('sort_order')->get() as $kind) {
            $poses[] = Section::make('وضعية: '.$kind->name_ar)->statePath("poses.{$kind->code}")->columns(['default' => 1, 'md' => 2])->collapsible()->schema([
                MediaSelect::make('static_media_id')->label('الصورة الثابتة')
                    ->helperText('تظهر دائماً، وهي الوحيدة على الأجهزة الضعيفة أو عند تفعيل «تقليل الحركة». اتركها فارغة إن لم تتوفر هذه الوضعية.'),
                TextInput::make('rive_state')->label('اسم حالة الحركة في Rive')->maxLength(60)->placeholder('explaining')
                    ->helperText('اختياري. يُحدَّد بعد رفع ملف Rive للشخصية.'),
            ]);
        }

        return $schema->components([
            Section::make('الشخصية')->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name_ar')->label('الاسم')->required()->maxLength(60)->live(onBlur: true)
                    ->unique(table: 'characters', column: 'name_ar', ignoreRecord: true)
                    ->validationMessages(['unique' => 'هذا الاسم مستعمل لشخصية أخرى.'])
                    ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
                TextInput::make('slug')->label('الرابط')->maxLength(200)->helperText('يُنشأ من الاسم تلقائياً ويمكن تعديله.'),
                TextInput::make('role_ar')->label('الدور')->required()->maxLength(200)->columnSpanFull()
                    ->helperText('جملة قصيرة تصف ما تفعله الشخصية، مثل: ينبّه إلى خطر أو خطأ شائع.'),
                ColorPicker::make('accent_color')->label('اللون')->required()->live()
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (! ContrastCheck::isHex($value)) {
                            $fail('اللون غير صحيح.');
                        } elseif (! ContrastCheck::passes($value)) {
                            $fail('اللون غير واضح على خلفية الصفحة (التباين '.number_format(ContrastCheck::ratio($value, ContrastCheck::background()), 2).' والمطلوب '.ContrastCheck::MINIMUM.' على الأقل). اختر لوناً أغمق.');
                        }
                    })
                    ->helperText(fn (Get $get) => ContrastCheck::isHex($get('accent_color'))
                        ? 'التباين مع خلفية الصفحة: '.number_format(ContrastCheck::ratio($get('accent_color'), ContrastCheck::background()), 2).' (المطلوب '.ContrastCheck::MINIMUM.' على الأقل).'
                        : 'يُستعمل لاسم الشخصية وإطار فقاعة الكلام.'),
                RiveSelect::make('rive_media_id')->label('ملف الحركة (Rive)'),
                Textarea::make('description')->label('وصف')->rows(3)->columnSpanFull(),
                TextInput::make('sort_order')->label('الترتيب')->numeric()->integer()->default(0),
                Toggle::make('is_active')->label('ظاهرة في الموقع')->default(true),
            ]),
            ...$poses,
            SeoSection::make('اتركها فارغة لاستعمال اسم الشخصية ودورها.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ColorColumn::make('accent_color')->label(''),
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('role_ar')->label('الدور')->limit(50)->wrap(),
                TextColumn::make('poses')->label('الوضعيات')->state(fn (Character $c) => ($missing = app(CharacterLibrary::class)->missing($c)) === [] ? 'مكتملة (5)' : 'ناقص: '.implode('، ', $missing))
                    ->color(fn (Character $c) => app(CharacterLibrary::class)->missing($c) === [] ? 'success' : 'warning'),
                TextColumn::make('cards')->label('البطاقات')->state(fn (Character $c) => DB::table('awareness_cards')->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')->where('character_poses.character_id', $c->id)->count()),
                IconColumn::make('is_active')->label('ظاهرة')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCharacters::route('/'),
            'create' => CreateCharacter::route('/create'),
            'edit' => EditCharacter::route('/{record}/edit'),
        ];
    }
}
