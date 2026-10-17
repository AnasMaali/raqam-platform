<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Support\ArabicSlug;
use App\Support\Deletion;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** News categories (decision 4): one level of sub-categories, drag to reorder, a description and SEO fields. */
class CategoryResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $modelLabel = 'تصنيف';

    protected static ?string $pluralModelLabel = 'التصنيفات';

    protected static ?string $navigationLabel = 'التصنيفات';

    protected static ?int $navigationSort = 30;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(80)->live(onBlur: true)
                ->rule(fn (Get $get, ?Category $record) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record) {
                    $twin = Category::query()->where('name_ar', (string) $value)->where('parent_id', $get('parent_id'))
                        ->when($record, fn (Builder $q) => $q->whereKeyNot($record->id))->exists();
                    $twin ? $fail('يوجد تصنيف بهذا الاسم في نفس المستوى.') : null;
                })
                ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
            TextInput::make('slug')->label('الرابط')->maxLength(200)->extraInputAttributes(['dir' => 'rtl'])
                ->helperText('يُنشأ من الاسم تلقائياً ويمكن تعديله.'),
            Select::make('parent_id')->label('تصنيف رئيسي')->native(false)
                ->helperText('اتركه فارغاً لتصنيف رئيسي. مستوى فرعي واحد فقط.')
                ->options(fn (?Category $record) => Category::query()->whereNull('parent_id')
                    ->when($record, fn (Builder $q) => $q->whereKeyNot($record->id))->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                ->disabled(fn (?Category $record) => $record !== null && $record->children()->exists()),
            Textarea::make('description')->label('الوصف')->rows(3)->helperText('يظهر في مقدمة صفحة التصنيف.')->columnSpanFull(),
            Toggle::make('is_highlighted')->label('قسم مميز في القائمة'),
            Toggle::make('is_active')->label('ظاهر')->default(true),
            SeoSection::make('اتركها فارغة لاستعمال اسم التصنيف ووصفه.')->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')
                ->orderByRaw('COALESCE(parent_id, id), parent_id NULLS FIRST, sort_order'))
            ->reorderable('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable()
                    ->formatStateUsing(fn (string $state, Category $record) => $record->parent_id ? '↳ '.$state : $state)
                    ->weight(fn (Category $record) => $record->parent_id ? null : 'bold'),
                TextColumn::make('parent.name_ar')->label('تحت')->placeholder('—'),
                IconColumn::make('is_highlighted')->label('مميز')->boolean(),
                IconColumn::make('is_active')->label('ظاهر')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('إذا كان في القوائم أو الصفحة الرئيسية فسيُزال منها. الأفضل إخفاؤه بدل حذفه.')
                    ->action(fn (Category $record) => Deletion::attempt($record, $record->name_ar, 'مواد أو تصنيفات فرعية')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
