<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\OrganizationSelect;
use App\Filament\Resources\Organizations\Pages\ManageOrganizations;
use App\Models\Organization;
use App\Support\ArabicNormalizer;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Companies and bodies (decisions 5.2, 5.3, 6.8): name, website, sector, city and founding year, shown with success
 * stories, Made in Nablus products, guests, initiatives and sponsors. They are added from those forms; here they are
 * completed and corrected. No delete: a company is kept by everything that names it.
 */
class OrganizationResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'جهة';

    protected static ?string $pluralModelLabel = 'الجهات والشركات';

    protected static ?string $navigationLabel = 'الجهات والشركات';

    protected static ?int $navigationSort = 35;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function canCreate(): bool
    {
        return false;   // added from the forms that need one (OrganizationSelect)
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(OrganizationSelect::fields())->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الجهة')->searchable(query: fn ($query, string $search) => $query->where('search_name', 'like', '%'.addcslashes(ArabicNormalizer::normalize($search), '%_\\').'%'))->sortable(),
                TextColumn::make('sector_name')->label('القطاع')->placeholder('—')
                    ->state(fn (Organization $r) => $r->sector_id ? DB::table('sectors')->where('id', $r->sector_id)->value('name_ar') : null),
                TextColumn::make('city_name')->label('المدينة')->placeholder('—')
                    ->state(fn (Organization $r) => $r->city_id ? DB::table('cities')->where('id', $r->city_id)->value('name_ar') : null),
                TextColumn::make('founded_year')->label('سنة التأسيس')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('sector_id')->label('القطاع')->options(fn () => DB::table('sectors')->orderBy('sort_order')->pluck('name_ar', 'id')->all()),
                SelectFilter::make('city_id')->label('المدينة')->options(fn () => DB::table('cities')->orderBy('name_ar')->pluck('name_ar', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل')->using(function (Organization $record, array $data) {
                    $record->update(OrganizationSelect::clean($data));

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOrganizations::route('/')];
    }
}
