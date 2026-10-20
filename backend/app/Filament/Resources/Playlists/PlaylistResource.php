<?php

namespace App\Filament\Resources\Playlists;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\SeoSection;
use App\Filament\Forms\VideoSelect;
use App\Filament\Resources\Playlists\Pages\CreatePlaylist;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Models\Playlist;
use App\Support\ArabicSlug;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Ordered lists of videos (decision 8.9); a podcast season is one (5.1). The order is set by dragging. */
class PlaylistResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Playlist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'قائمة تشغيل';

    protected static ?string $pluralModelLabel = 'قوائم التشغيل';

    protected static ?string $navigationLabel = 'قوائم التشغيل';

    protected static ?int $navigationSort = 26;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('القائمة')->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name_ar')->label('الاسم')->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
                TextInput::make('slug')->label('الرابط')->maxLength(200)->helperText('يُنشأ من الاسم تلقائياً ويمكن تعديله.'),
                Select::make('section_id')->label('القسم')->native(false)->placeholder('بدون قسم')
                    ->options(fn () => DB::table('sections')->orderBy('sort_order')->pluck('name_ar', 'id')->all()),
                Select::make('season_id')->label('موسم بودكاست')->native(false)->placeholder('ليست موسماً')
                    ->options(fn (?Playlist $record) => DB::table('podcast_seasons')->where(fn ($q) => $q->whereNull('playlist_id')->when($record, fn ($q) => $q->orWhere('playlist_id', $record->id)))
                        ->orderBy('season_number')->get()->mapWithKeys(fn ($s) => [$s->id => 'الموسم '.$s->season_number.($s->title_ar ? ' — '.$s->title_ar : '')])->all())
                    ->helperText('كل موسم بودكاست قائمة تشغيل واحدة.'),
                Textarea::make('description')->label('الوصف')->rows(3)->columnSpanFull(),
                MediaSelect::make('cover_media_id')->label('الغلاف'),
                TextInput::make('sort_order')->label('الترتيب')->numeric()->integer()->default(0),
                Toggle::make('is_visible')->label('ظاهرة في الموقع')->default(true),
            ]),
            Section::make('الفيديوهات (اسحب للترتيب)')->schema([
                Repeater::make('videos')->hiddenLabel()->reorderable()->defaultItems(0)->addActionLabel('إضافة فيديو')->maxItems(500)
                    ->schema([VideoSelect::make('video_id')->hiddenLabel()->required()]),
            ]),
            SeoSection::make('اتركها فارغة لاستعمال اسم القائمة ووصفها.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('section.name_ar')->label('القسم')->placeholder('—'),
                TextColumn::make('videos_count')->label('الفيديوهات')->state(fn (Playlist $p) => DB::table('playlist_videos')->where('playlist_id', $p->id)->count()),
                IconColumn::make('is_visible')->label('ظاهرة')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('تُحذف القائمة فقط؛ الفيديوهات تبقى في المكتبة.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaylists::route('/'),
            'create' => CreatePlaylist::route('/create'),
            'edit' => EditPlaylist::route('/{record}/edit'),
        ];
    }
}
