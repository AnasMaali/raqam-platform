<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Tag;
use App\Support\ArabicSlug;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Free tags (decision 4.4). Normally they are typed into the post form; this screen tidies them up. */
class TagResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'وسم';

    protected static ?string $pluralModelLabel = 'الوسوم';

    protected static ?string $navigationLabel = 'الوسوم';

    protected static ?int $navigationSort = 31;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(80)->live(onBlur: true)
                ->rule(fn (?Tag $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record) {
                    // Same word once hamza, ta marbuta and diacritics are ignored counts as a duplicate.
                    $twin = Tag::query()->whereRaw('search_name = normalize_ar(?)', [(string) $value])
                        ->when($record, fn (Builder $q) => $q->whereKeyNot($record->id))->exists();
                    $twin ? $fail('يوجد وسم بهذا الاسم أو ما يشبهه.') : null;
                })
                ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
            TextInput::make('slug')->label('الرابط')->maxLength(200)->helperText('يُنشأ من الاسم تلقائياً ويمكن تعديله.'),
            SeoSection::make('اتركها فارغة لاستعمال اسم الوسم.')->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect(['tags.*', 'posts_count' => DB::table('post_tags')
                ->selectRaw('count(*)')->whereColumn('post_tags.tag_id', 'tags.id')]))
            ->defaultSort('name_ar')
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('search_name ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])),
                TextColumn::make('posts_count')->label('عدد المواد')->sortable()->numeric(),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('يُزال الوسم من كل المواد التي تستعمله.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
        ];
    }
}
