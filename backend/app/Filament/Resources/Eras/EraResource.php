<?php

namespace App\Filament\Resources\Eras;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Eras\Pages\ManageEras;
use App\Models\Era;
use App\Support\Deletion;
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

/** Eras of Nablus economic history (decision 5.4): the list the history items choose from. */
class EraResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Era::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $modelLabel = 'حقبة';

    protected static ?string $pluralModelLabel = 'الحقب التاريخية';

    protected static ?string $navigationLabel = 'الحقب التاريخية';

    protected static ?int $navigationSort = 34;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('اسم الحقبة')->required()->maxLength(80)
                ->unique(ignoreRecord: true)->validationMessages(['unique' => 'توجد حقبة بهذا الاسم.']),
            TextInput::make('year_from')->label('من سنة')->numeric()->integer()->minValue(-9999)->maxValue(32767),
            TextInput::make('year_to')->label('إلى سنة')->numeric()->integer()->minValue(-9999)->maxValue(32767)
                ->gte('year_from')->validationMessages(['gte' => 'سنة النهاية يجب ألا تسبق سنة البداية.']),
        ])->columns(['default' => 1, 'md' => 3]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('sort_order'))
            ->reorderable('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->label('الحقبة')->searchable(),
                TextColumn::make('year_from')->label('من')->placeholder('—'),
                TextColumn::make('year_to')->label('إلى')->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->action(fn (Era $record) => Deletion::attempt($record, $record->name_ar, 'مواد تاريخية')),
            ])
            ->headerActions([CreateAction::make()->label('حقبة جديدة')->mutateDataUsing(fn (array $data) => $data + ['sort_order' => (int) Era::query()->max('sort_order') + 1])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEras::route('/')];
    }
}
