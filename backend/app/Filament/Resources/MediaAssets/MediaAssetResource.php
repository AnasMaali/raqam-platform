<?php

namespace App\Filament\Resources\MediaAssets;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Concerns\NormalizedGlobalSearch;
use App\Filament\Forms\Components\FocalPoint;
use App\Filament\Resources\MediaAssets\Pages\CreateMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Media\MediaInUse;
use App\Media\MediaStorage;
use App\Media\MediaThumb;
use App\Media\MediaTrash;
use App\Media\MediaUsage;
use App\Models\MediaAsset;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Number;

/** The media library (decisions 9.1 – 9.9): upload once, use anywhere, see where it is used. */
class MediaAssetResource extends Resource
{
    use HasNumericRecordKey, NormalizedGlobalSearch;

    protected static ?string $model = MediaAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $recordTitleAttribute = 'original_name';

    protected static ?string $modelLabel = 'ملف';

    protected static ?string $pluralModelLabel = 'مكتبة الوسائط';

    protected static ?string $navigationLabel = 'مكتبة الوسائط';

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public const TYPES = [
        'image' => 'صور',
        'svg' => 'صور SVG',
        'pdf' => 'ملفات PDF',
        'video' => 'مقاطع MP4',
        'rive' => 'ملفات Rive',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الملف')->visible(fn (?MediaAsset $record) => $record === null)->schema([
                FileUpload::make('file')
                    ->label('الملف')
                    ->helperText('صور JPG وPNG وWebP وAVIF حتى 25 ميغابايت (تُضغط تلقائياً)، SVG حتى 2، PDF حتى 20، مقطع MP4 حتى 30 وبمدة 90 ثانية، وملف Rive حتى 5.')
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml', 'application/pdf', 'video/mp4', 'application/mp4', 'application/octet-stream'])
                    ->maxSize((int) (max(config('media.limits.image_upload'), config('media.limits.video')) / 1024)),
                Select::make('visibility')
                    ->label('الظهور')
                    ->options(['public' => 'عام', 'private' => 'خاص (لا يُعرض للزوار)'])
                    ->default('public')->required()->native(false),
            ]),
            Section::make('بيانات الملف')->visible(fn (?MediaAsset $record) => $record !== null)->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('original_name')->label('الاسم الأصلي'),
                TextEntry::make('mime_type')->label('النوع'),
                TextEntry::make('size_bytes')->label('الحجم')->formatStateUsing(fn ($state) => Number::fileSize((int) $state)),
                TextEntry::make('duration_seconds')->label('المدة')->visible(fn (MediaAsset $record) => $record->duration_seconds !== null)
                    ->formatStateUsing(fn ($state) => round((float) $state).' ثانية'),
                TextEntry::make('dimensions')->label('الأبعاد')
                    ->state(fn (MediaAsset $record) => $record->width ? "{$record->width} × {$record->height}" : '—'),
                TextEntry::make('visibility')->label('الظهور')->formatStateUsing(fn ($state) => $state === 'public' ? 'عام' : 'خاص'),
                TextEntry::make('uploader.name')->label('رفعه')->placeholder('—'),
            ]),
            Section::make('الوصف')->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('alt_text')->label('النص البديل (Alt)')->maxLength(250)
                    ->helperText('إجباري للصور العامة. يصف الصورة لمن لا يراها ولمحركات البحث.')
                    ->required(fn (?MediaAsset $record) => $record === null || ($record->isImage() && $record->visibility === 'public'))
                    ->columnSpanFull(),
                TextInput::make('caption')->label('التعليق')->maxLength(500),
                TextInput::make('credit')->label('المصدر / الحقوق')->maxLength(200),
            ]),
            Section::make('نقطة التركيز')->visible(fn (?MediaAsset $record) => $record?->isRaster() ?? false)->schema([
                FocalPoint::make('focal')->hiddenLabel()
                    ->imageUrl(fn (MediaAsset $record) => app(MediaStorage::class)->url($record)),
            ]),
            Section::make('أين تُستخدم؟')->visible(fn (?MediaAsset $record) => $record !== null)->schema([
                TextEntry::make('usage')->hiddenLabel()->placeholder('غير مستخدمة في أي مكان.')
                    ->state(function (MediaAsset $record) {
                        $usages = app(MediaUsage::class)->of($record);

                        return $usages === [] ? null : collect($usages)->map(fn ($u) => "{$u['label']} — {$u['id']}")->all();
                    })->bulleted(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->contentGrid(['default' => 2, 'md' => 3, 'xl' => 5])
            ->columns([
                Stack::make([
                    ImageColumn::make('preview')->label('')
                        ->state(fn (MediaAsset $record) => MediaThumb::url($record))
                        ->imageHeight('9rem')->extraImgAttributes(['style' => 'object-fit: cover; width: 100%;', 'loading' => 'lazy', 'alt' => '']),
                    TextColumn::make('original_name')->weight('medium')->limit(28)
                        ->searchable(query: fn (Builder $query, string $search) => $query->search($search)),
                    TextColumn::make('alt_text')->color('gray')->limit(40)->placeholder('بلا نص بديل'),
                    TextColumn::make('size_bytes')->color('gray')->formatStateUsing(fn ($state) => Number::fileSize((int) $state)),
                ]),
            ])
            ->filters([
                SelectFilter::make('type')->label('النوع')->options(self::TYPES)->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'image' => $query->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/avif']),
                    'svg' => $query->where('mime_type', 'image/svg+xml'),
                    'pdf' => $query->where('mime_type', 'application/pdf'),
                    'video' => $query->where('mime_type', 'video/mp4'),
                    'rive' => $query->where('mime_type', 'application/x-rive'),
                    default => $query,
                }),
                SelectFilter::make('visibility')->label('الظهور')->options(['public' => 'عام', 'private' => 'خاص']),
                SelectFilter::make('uploaded_by')->label('رفعه')
                    ->options(fn () => User::query()->where('user_type', 'staff')->orderBy('name')->pluck('name', 'id')->all()),
                TrashedFilter::make()->label('سلة المحذوفات'),
            ])
            // Each picture goes through the trash rules: one still used is kept (21.17).
            ->toolbarActions([
                BulkAction::make('trashSelected')->label('نقل المحدد إلى السلة')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $done = $kept = 0;
                        foreach ($records as $record) {
                            try {
                                $record->trashed() ? null : app(MediaTrash::class)->trash($record);
                                $done++;
                            } catch (MediaInUse) {
                                $kept++;
                            }
                        }
                        Notification::make()->title("نُقل إلى السلة: {$done}")->body($kept > 0 ? "بقي {$kept} لأنه مستخدم." : null)->{$kept > 0 ? 'warning' : 'success'}()->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                self::trashAction(),
                RestoreAction::make()->label('استرجاع'),
                self::purgeAction(),
            ]);
    }

    public static function trashAction(): Action
    {
        return Action::make('trash')
            ->label('نقل إلى السلة')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->visible(fn (MediaAsset $record) => ! $record->trashed())
            ->requiresConfirmation()
            ->action(function (MediaAsset $record) {
                try {
                    app(MediaTrash::class)->trash($record);
                } catch (MediaInUse $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }
                Notification::make()->success()->title('نُقل إلى السلة.')->send();
            });
    }

    public static function purgeAction(): Action
    {
        return Action::make('purge')
            ->label('حذف نهائي')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (MediaAsset $record) => $record->trashed())
            ->requiresConfirmation()->modalDescription('يُحذف الملف ونسخه نهائياً ولا يمكن استرجاعه.')
            ->action(function (MediaAsset $record) {
                try {
                    app(MediaTrash::class)->purge($record);
                } catch (MediaInUse $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }
                Notification::make()->success()->title('تم الحذف نهائياً.')->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;   // only through the trash action, which knows about usage
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    protected static function globalSearchText(): string
    {
        return "normalize_ar(media_assets.original_name || ' ' || coalesce(media_assets.alt_text, '') || ' ' || coalesce(media_assets.caption, ''))";
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaAssets::route('/'),
            'create' => CreateMediaAsset::route('/create'),
            'edit' => EditMediaAsset::route('/{record}/edit'),
        ];
    }
}
