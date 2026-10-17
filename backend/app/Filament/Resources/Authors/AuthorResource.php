<?php

namespace App\Filament\Resources\Authors;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Authors\Pages\CreateAuthor;
use App\Filament\Resources\Authors\Pages\EditAuthor;
use App\Filament\Resources\Authors\Pages\ListAuthors;
use App\Models\Author;
use App\Support\ArabicSlug;
use App\Taxonomy\PersonLinks;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Writers (decision 6): bylines, not panel users. A piece with no writer is shown as «فريق رَقَم»,
 * which needs no row here.
 */
class AuthorResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Author::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $modelLabel = 'كاتب';

    protected static ?string $pluralModelLabel = 'الكتّاب';

    protected static ?string $navigationLabel = 'الكتّاب';

    protected static ?int $navigationSort = 35;

    protected static string|\UnitEnum|null $navigationGroup = 'الأشخاص';

    public static function form(Schema $schema): Schema
    {
        $links = [];
        foreach (app(PersonLinks::class)->platforms() as $code => $name) {
            $links[] = TextInput::make("links.{$code}")->label($name)->url()->maxLength(2048)->placeholder('https://')
                ->extraInputAttributes(['dir' => 'ltr']);
        }

        return $schema->components([
            Section::make('الكاتب')->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
                TextInput::make('slug')->label('الرابط')->maxLength(200)->helperText('يُنشأ من الاسم تلقائياً ويمكن تعديله.'),
                TextInput::make('job_title')->label('المسمى')->maxLength(120),
                MediaSelect::make('photo_media_id')->label('الصورة'),
                Textarea::make('bio')->label('نبذة')->rows(4)->columnSpanFull(),
                TextInput::make('team_sort_order')->label('ترتيبه في صفحة «فريق العمل»')->numeric()->integer()->minValue(0)->maxValue(32767)
                    ->helperText('اتركه فارغاً إذا لا يظهر في صفحة الفريق.'),
                Toggle::make('is_active')->label('فعّال')->default(true),
            ]),
            Section::make('الروابط')->columns(['default' => 1, 'md' => 2])->collapsed()->schema($links),
            SeoSection::make('اتركها فارغة لاستعمال اسم الكاتب ونبذته.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('search_name ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])),
                TextColumn::make('job_title')->label('المسمى')->placeholder('—'),
                TextColumn::make('team_sort_order')->label('ترتيب الفريق')->placeholder('—'),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('فعّال'),
                TrashedFilter::make()->label('المحذوفون'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('تبقى مواده محفوظة ويمكن استرجاعه لاحقاً.'),
                RestoreAction::make()->label('استرجاع'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthors::route('/'),
            'create' => CreateAuthor::route('/create'),
            'edit' => EditAuthor::route('/{record}/edit'),
        ];
    }
}
