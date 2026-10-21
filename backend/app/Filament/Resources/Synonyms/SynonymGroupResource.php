<?php

namespace App\Filament\Resources\Synonyms;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Synonyms\Pages\CreateSynonymGroup;
use App\Filament\Resources\Synonyms\Pages\EditSynonymGroup;
use App\Filament\Resources\Synonyms\Pages\ListSynonymGroups;
use App\Models\SynonymGroup;
use App\Search\SynonymLibrary;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The synonyms of the search (10.9): groups of words that find each other. Every change reaches the engine. */
class SynonymGroupResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = SynonymGroup::class;

    protected static ?string $slug = 'synonyms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $modelLabel = 'مجموعة مترادفات';

    protected static ?string $pluralModelLabel = 'المترادفات';

    protected static ?string $navigationLabel = 'المترادفات';

    protected static string|\UnitEnum|null $navigationGroup = 'البحث';

    protected static ?int $navigationSort = 61;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TagsInput::make('terms')->label('الكلمات')->required()->splitKeys(['Enter', 'Tab', '،', ','])
                ->helperText('كل كلمة تجد الباقي في البحث. اكتب الكلمة واضغط Enter. الهمزة والتاء المربوطة و«ال» لا تفرق: «الشركة» و«شركة» كلمة واحدة.')
                ->placeholder('كلمة جديدة')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect(['synonym_groups.*', 'words' => DB::table('synonym_terms')
                ->selectRaw("string_agg(term, '، ' ORDER BY term)")->whereColumn('synonym_terms.group_id', 'synonym_groups.id')]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('words')->label('الكلمات')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereExists(fn ($q) => $q->selectRaw('1')->from('synonym_terms')
                        ->whereColumn('synonym_terms.group_id', 'synonym_groups.id')->whereRaw("normalize_ar(term) ILIKE '%' || normalize_ar(?) || '%'", [$search]))),
                TextColumn::make('created_at')->label('أُضيفت')->dateTime('Y-m-d H:i', 'Asia/Hebron'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('تتوقف هذه الكلمات عن إيجاد بعضها في البحث.')
                    ->using(function (SynonymGroup $record): bool {
                        app(SynonymLibrary::class)->delete($record->id, auth()->id());

                        return true;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSynonymGroups::route('/'),
            'create' => CreateSynonymGroup::route('/create'),
            'edit' => EditSynonymGroup::route('/{record}/edit'),
        ];
    }
}
