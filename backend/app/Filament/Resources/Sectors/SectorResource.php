<?php

namespace App\Filament\Resources\Sectors;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Sectors\Pages\ManageSectors;
use App\Models\Sector;
use App\Support\ArabicSlug;
use App\Support\Deletion;
use App\Support\UniqueSlug;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Economic sectors (decisions 5.2, 5.3): what a company's sector is chosen from, for success stories and Made in Nablus. */
class SectorResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Sector::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'قطاع';

    protected static ?string $pluralModelLabel = 'القطاعات الاقتصادية';

    protected static ?string $navigationLabel = 'القطاعات الاقتصادية';

    protected static ?int $navigationSort = 36;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('اسم القطاع')->required()->maxLength(80)
                ->unique(ignoreRecord: true)->validationMessages(['unique' => 'يوجد قطاع بهذا الاسم.']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('sort_order')->orderBy('id'))
            ->reorderable('sort_order')
            ->paginated(false)
            ->columns([TextColumn::make('name_ar')->label('القطاع')->searchable()])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->action(fn (Sector $record) => Deletion::attempt($record, $record->name_ar, 'جهات')),
            ])
            ->headerActions([CreateAction::make()->label('قطاع جديد')->mutateDataUsing(fn (array $data) => $data + [
                'slug' => UniqueSlug::for('sectors', ArabicSlug::make($data['name_ar']), fallback: 'sector'),
                'sort_order' => (int) Sector::query()->max('sort_order') + 1,
            ])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSectors::route('/')];
    }
}
