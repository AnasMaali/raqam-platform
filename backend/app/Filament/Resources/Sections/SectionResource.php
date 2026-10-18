<?php

namespace App\Filament\Resources\Sections;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Models\Section;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The seven special sections (decisions 5.8, 5.9): change the look and the order, hide or show; never add or delete. */
class SectionResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Section::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $modelLabel = 'قسم';

    protected static ?string $pluralModelLabel = 'الأقسام الخاصة';

    protected static ?string $navigationLabel = 'الأقسام الخاصة';

    protected static ?int $navigationSort = 32;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('address')->label('عنوان القسم (ثابت)')->state(fn (?Section $record) => $record ? '/'.$record->slug : '—'),
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(80),
            Textarea::make('description')->label('الوصف')->rows(3)->helperText('يظهر في مقدمة صفحة القسم.')->columnSpanFull(),
            MediaSelect::make('cover_media_id')->label('الصورة'),
            Toggle::make('is_visible')->label('ظاهر للزوار'),
            SeoSection::make('اتركها فارغة لاستعمال اسم القسم ووصفه.')->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('sort_order'))
            ->reorderable('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->label('القسم')->searchable(),
                TextColumn::make('slug')->label('العنوان')->formatStateUsing(fn (string $state) => '/'.$state),
                IconColumn::make('is_visible')->label('ظاهر')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSections::route('/'),
            'edit' => EditSection::route('/{record}/edit'),
        ];
    }
}
