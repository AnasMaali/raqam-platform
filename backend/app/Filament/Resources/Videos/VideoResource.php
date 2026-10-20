<?php

namespace App\Filament\Resources\Videos;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\MediaSelect;
use App\Filament\Resources\Videos\Pages\CreateVideo;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Media\MediaStorage;
use App\Models\Video;
use App\Video\ChapterTime;
use App\Video\YouTubeUrl;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * The video library (decision 8): YouTube videos added by pasting a link, short MP4 clips uploaded directly,
 * the title and description the site shows, chapters with times, and where each video is used.
 */
class VideoResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Video::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $modelLabel = 'فيديو';

    protected static ?string $pluralModelLabel = 'الفيديوهات';

    protected static ?string $navigationLabel = 'مكتبة الفيديو';

    protected static ?int $navigationSort = 25;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('إضافة فيديو')->visible(fn (?Video $record) => $record === null)->schema([
                Select::make('source')->label('المصدر')->native(false)->default('youtube')->live()->required()->selectablePlaceholder(false)
                    ->options(['youtube' => 'رابط يوتيوب', 'upload' => 'مقطع قصير من الجهاز (MP4)']),
                TextInput::make('url')->label('رابط الفيديو')->placeholder('https://www.youtube.com/watch?v=…')->maxLength(2048)
                    ->visible(fn (Get $get) => $get('source') === 'youtube')->required(fn (Get $get) => $get('source') === 'youtube')
                    ->helperText('يُقبل الرابط العادي أو المختصر (youtu.be) أو Shorts. العنوان والوصف والمدة تُجلب تلقائياً.'),
                Toggle::make('manual')->label('إدخال البيانات يدوياً')->live()->visible(fn (Get $get) => $get('source') === 'youtube')
                    ->helperText('يُفتح تلقائياً إذا لم يتوفر مفتاح يوتيوب أو تعذّر الجلب.'),
                TextInput::make('manual_title')->label('العنوان')->maxLength(255)
                    ->visible(fn (Get $get) => $get('source') === 'youtube' && $get('manual'))->required(fn (Get $get) => $get('source') === 'youtube' && $get('manual')),
                TextInput::make('manual_duration')->label('المدة (اختياري)')->placeholder('12:30')->maxLength(10)
                    ->visible(fn (Get $get) => $get('source') === 'youtube' && $get('manual')),
                FileUpload::make('file')->label('ملف MP4')->storeFiles(false)
                    ->visible(fn (Get $get) => $get('source') === 'upload')->required(fn (Get $get) => $get('source') === 'upload')
                    ->acceptedFileTypes(['video/mp4', 'application/mp4'])->maxSize((int) (config('media.limits.video') / 1024))
                    ->helperText('حتى 90 ثانية و30 ميغابايت، بترميز H.264. الفيديوهات الطويلة تُرفع على يوتيوب.'),
                TextInput::make('upload_title')->label('العنوان')->maxLength(255)
                    ->visible(fn (Get $get) => $get('source') === 'upload')->required(fn (Get $get) => $get('source') === 'upload'),
                Textarea::make('upload_description')->label('الوصف')->rows(3)->maxLength(5000)->visible(fn (Get $get) => $get('source') === 'upload'),
                MediaSelect::make('upload_poster_media_id')->label('صورة الغلاف')->visible(fn (Get $get) => $get('source') === 'upload'),
            ]),

            Section::make('الفيديو')->visible(fn (?Video $record) => $record !== null)->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('source')->label('المصدر')->formatStateUsing(fn ($state) => $state === 'youtube' ? 'يوتيوب' : 'مقطع مرفوع'),
                TextEntry::make('duration_seconds')->label('المدة')->placeholder('غير معروفة')->formatStateUsing(fn ($state) => ChapterTime::format((int) $state)),
                TextEntry::make('link')->label('على يوتيوب')->visible(fn (Video $record) => $record->isYoutube())
                    ->state(fn (Video $record) => new HtmlString('<a href="'.e(YouTubeUrl::watchUrl($record->youtube->youtube_id)).'" target="_blank" rel="noopener noreferrer" dir="ltr">'.e($record->youtube->youtube_id).'</a>')),
                TextEntry::make('availability')->label('الحالة على يوتيوب')->visible(fn (Video $record) => $record->isYoutube())
                    ->state(fn (Video $record) => self::AVAILABILITY[$record->youtube->availability] ?? $record->youtube->availability),
                TextEntry::make('source_title')->label('عنوانه على يوتيوب')->visible(fn (Video $record) => $record->isYoutube())->columnSpanFull()
                    ->state(fn (Video $record) => $record->youtube->source_title),
            ]),
            Section::make('كما يظهر في الموقع')->visible(fn (?Video $record) => $record !== null)->schema([
                TextInput::make('display_title')->label('العنوان المعروض')->maxLength(255)
                    ->helperText('اتركه فارغاً لاستعمال عنوان يوتيوب. تعديله لا يغيّر شيئاً على يوتيوب.')
                    ->required(fn (?Video $record) => $record?->source === 'upload'),
                Textarea::make('display_description')->label('الوصف المعروض')->rows(4)->maxLength(5000)->helperText('اتركه فارغاً لاستعمال وصف يوتيوب.'),
                MediaSelect::make('poster_media_id')->label('صورة الغلاف')->helperText('اختيارية؛ الافتراضي صورة يوتيوب المصغّرة.'),
            ]),
            Section::make('المحاور (أوقات داخل الفيديو)')->visible(fn (?Video $record) => $record !== null)->collapsed()->schema([
                Repeater::make('chapters')->hiddenLabel()->defaultItems(0)->addActionLabel('إضافة محور')->maxItems(100)->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('time')->label('الوقت')->placeholder('12:30')->required()->maxLength(10),
                        TextInput::make('title')->label('عنوان المحور')->required()->maxLength(160)->columnSpan(['md' => 2]),
                    ])->helperText('الوقت مثل 12:30 أو 1:05:00، ولا يتجاوز مدة الفيديو. الضغط على المحور في الموقع ينقل الفيديو لوقته.'),
            ]),
            Section::make('أين يُستخدم؟')->visible(fn (?Video $record) => $record !== null)->schema([
                TextEntry::make('usage')->hiddenLabel()->placeholder('غير مستخدم في أي مكان.')->bulleted()->state(fn (Video $record) => self::usage($record)),
            ]),
        ]);
    }

    private const AVAILABILITY = ['ok' => 'متاح', 'private' => 'صار خاصاً', 'removed' => 'محذوف من يوتيوب', 'unknown' => 'لم يُفحص بعد'];

    /** @return list<string> the items and playlists that use the video */
    public static function usage(Video $video): array
    {
        $kinds = ['primary' => 'فيديو رئيسي', 'body' => 'داخل النص'];
        $lines = DB::table('video_usage')->join('posts', 'posts.id', '=', 'video_usage.post_id')->where('video_usage.video_id', $video->id)
            ->orderByDesc('posts.id')->limit(50)->get(['posts.title', 'video_usage.usage'])
            ->map(fn ($r) => $r->title.' — '.($kinds[$r->usage] ?? $r->usage))->all();

        $playlists = DB::table('playlist_videos')->join('playlists', 'playlists.id', '=', 'playlist_videos.playlist_id')
            ->where('playlist_videos.video_id', $video->id)->pluck('playlists.name_ar')->map(fn ($n) => 'قائمة التشغيل: '.$n)->all();

        return [...$lines, ...$playlists];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('youtube', 'poster'))
            ->columns([
                ImageColumn::make('thumb')->label('')->state(fn (Video $v) => self::thumbnail($v))->imageHeight('4rem')->extraImgAttributes(['loading' => 'lazy', 'alt' => '']),
                TextColumn::make('title')->label('العنوان')->state(fn (Video $v) => $v->title())->wrap()->limit(70)
                    ->searchable(query: fn (Builder $query, string $search) => $query->search($search)),
                TextColumn::make('source')->label('المصدر')->badge()->formatStateUsing(fn ($state) => $state === 'youtube' ? 'يوتيوب' : 'مقطع'),
                TextColumn::make('duration_seconds')->label('المدة')->placeholder('—')->formatStateUsing(fn ($state) => ChapterTime::format((int) $state)),
                TextColumn::make('availability')->label('الحالة')->badge()->state(fn (Video $v) => $v->isYoutube() ? $v->youtube->availability : 'ok')
                    ->formatStateUsing(fn ($state) => self::AVAILABILITY[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'ok' => 'success', 'unknown' => 'gray', default => 'danger'
                    }),
            ])
            ->filters([
                SelectFilter::make('source')->label('المصدر')->options(['youtube' => 'يوتيوب', 'upload' => 'مقطع مرفوع']),
                SelectFilter::make('section')->label('القسم')
                    ->options(fn () => DB::table('sections')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                    ->query(fn (Builder $query, array $data) => ($data['value'] ?? null) === null || ! ctype_digit((string) $data['value']) ? $query : $query->inSection((int) $data['value'])),
                SelectFilter::make('playlist')->label('قائمة التشغيل')
                    ->options(fn () => DB::table('playlists')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                    ->query(fn (Builder $query, array $data) => ($data['value'] ?? null) === null || ! ctype_digit((string) $data['value']) ? $query : $query->inPlaylist((int) $data['value'])),
                SelectFilter::make('availability')->label('الحالة على يوتيوب')->options(self::AVAILABILITY)
                    ->query(fn (Builder $query, array $data) => ($data['value'] ?? null) === null ? $query
                        : $query->whereIn('videos.id', DB::table('youtube_videos')->where('availability', (string) $data['value'])->select('video_id'))),
                TrashedFilter::make()->label('المحذوفة'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->modalDescription('الفيديو المستخدم في مادة منشورة لا يُحذف نهائياً؛ يبقى في السلة ويمكن استرجاعه.'),
                RestoreAction::make()->label('استرجاع'),
            ]);
    }

    public static function thumbnail(Video $video): ?string
    {
        if ($video->poster !== null) {
            return app(MediaStorage::class)->url($video->poster);
        }

        return $video->youtube?->thumbnail_url;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideos::route('/'),
            'create' => CreateVideo::route('/create'),
            'edit' => EditVideo::route('/{record}/edit'),
        ];
    }
}
