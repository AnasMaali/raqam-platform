<?php

namespace App\Filament\Resources\Guests;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\Guests\Pages\EditGuest;
use App\Filament\Resources\Guests\Pages\ListGuests;
use App\Models\Guest;
use App\Models\GuestRole;
use App\Models\Organization;
use App\Support\ArabicSlug;
use App\Support\UniqueSlug;
use App\Taxonomy\PersonLinks;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Guests of podcast episodes and round-table sessions (decisions 6.7 – 6.11). A guest is added once and
 * linked to any number of items; the part played (host, guest, panelist) is chosen on each item.
 */
class GuestResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Guest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static ?string $modelLabel = 'ضيف';

    protected static ?string $pluralModelLabel = 'الضيوف';

    protected static ?string $navigationLabel = 'الضيوف';

    protected static ?int $navigationSort = 36;

    protected static string|\UnitEnum|null $navigationGroup = 'الأشخاص';

    public static function form(Schema $schema): Schema
    {
        $links = [];
        foreach (app(PersonLinks::class)->platforms() as $code => $name) {
            $links[] = TextInput::make("links.{$code}")->label($name)->url()->maxLength(2048)->placeholder('https://')
                ->extraInputAttributes(['dir' => 'ltr']);
        }

        return $schema->components([
            Section::make('الضيف')->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
                TextInput::make('slug')->label('الرابط')->maxLength(200)->helperText('يُنشأ من الاسم تلقائياً ويمكن تعديله.'),
                TextInput::make('job_title')->label('المنصب')->maxLength(120),
                Select::make('organization_id')->label('الجهة / الشركة')->native(false)->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Organization::query()->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? Organization::query()->find((int) $value)?->name : null)
                    ->createOptionForm([
                        TextInput::make('name')->label('اسم الجهة')->required()->maxLength(160),
                        TextInput::make('website')->label('الموقع')->url()->maxLength(2048)->extraInputAttributes(['dir' => 'ltr']),
                    ])
                    ->createOptionUsing(fn (array $data) => Organization::query()->create([
                        'name' => $data['name'], 'website' => $data['website'] ?: null,
                        'slug' => UniqueSlug::for('organizations', $data['name'], fallback: 'org'),
                    ])->id),
                MediaSelect::make('photo_media_id')->label('الصورة'),
                Textarea::make('bio')->label('نبذة')->rows(4)->columnSpanFull(),
            ]),
            Section::make('الروابط')->columns(['default' => 1, 'md' => 2])->collapsed()->schema($links),
            Section::make('ظهوراته')->visible(fn (?Guest $record) => $record !== null)->schema([
                TextEntry::make('appearances')->hiddenLabel()->placeholder('لم يظهر في أي مادة بعد.')->bulleted()
                    ->state(fn (Guest $record) => self::appearances($record)),
            ]),
            SeoSection::make('اتركها فارغة لاستعمال اسم الضيف ونبذته.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('search_name ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])),
                TextColumn::make('job_title')->label('المنصب')->placeholder('—'),
                TextColumn::make('organization.name')->label('الجهة')->placeholder('—'),
            ])
            ->filters([TrashedFilter::make()->label('المحذوفون')])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('تبقى ظهوراته في المواد ويمكن استرجاعه لاحقاً.'),
                RestoreAction::make()->label('استرجاع'),
            ]);
    }

    /** @return list<string> "title — role" for each item the guest appears in (6.11) */
    public static function appearances(Guest $guest): array
    {
        $roles = GuestRole::options();

        return DB::table('post_guests')->join('posts', 'posts.id', '=', 'post_guests.post_id')
            ->where('post_guests.guest_id', $guest->id)->orderByDesc('posts.id')->limit(50)
            ->get(['posts.title', 'post_guests.role_code'])
            ->map(fn ($row) => $row->title.' — '.($roles[$row->role_code] ?? $row->role_code))->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuests::route('/'),
            'create' => CreateGuest::route('/create'),
            'edit' => EditGuest::route('/{record}/edit'),
        ];
    }
}
