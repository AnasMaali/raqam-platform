<?php

namespace App\Filament\Resources\Advertisers;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\AdvertiserSelect;
use App\Filament\Resources\Advertisers\Pages\ManageAdvertisers;
use App\Models\Advertiser;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The advertisers (13.3): the company and the person to call. An advertiser with campaigns cannot be deleted. */
class AdvertiserResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Advertiser::class;

    protected static ?string $slug = 'advertisers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'معلن';

    protected static ?string $pluralModelLabel = 'المعلنون';

    protected static ?string $navigationLabel = 'المعلنون';

    protected static string|\UnitEnum|null $navigationGroup = 'الإعلانات';

    protected static ?int $navigationSort = 72;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([...AdvertiserSelect::fields(), Textarea::make('notes')->label('ملاحظات داخلية')->maxLength(2000)->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('organization')->withCount('campaigns'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('organization.name')->label('الجهة')->searchable(),
                TextColumn::make('contact_name')->label('المسؤول')->placeholder('—'),
                TextColumn::make('contact_phone')->label('الهاتف')->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('contact_email')->label('البريد')->placeholder('—'),
                TextColumn::make('campaigns_count')->label('الحملات'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل')->using(function (Advertiser $record, array $data) {
                    $record->update(AdvertiserSelect::validate($data + ['organization_id' => $record->organization_id], $record->id));

                    return $record;
                }),
                DeleteAction::make()->label('حذف')->visible(fn (Advertiser $r) => $r->campaigns_count === 0),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdvertisers::route('/')];
    }
}
