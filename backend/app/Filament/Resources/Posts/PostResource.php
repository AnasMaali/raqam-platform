<?php

namespace App\Filament\Resources\Posts;

use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Filament\Actions\Destructive;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Concerns\NormalizedGlobalSearch;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Media\MediaThumb;
use App\Models\Category;
use App\Models\Post;
use App\Models\PostKind;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** All content (decision 3): the 11 kinds in one list, one editor. */
class PostResource extends Resource
{
    use HasNumericRecordKey, NormalizedGlobalSearch;

    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $modelLabel = 'مادة';

    protected static ?string $pluralModelLabel = 'المواد';

    protected static ?string $navigationLabel = 'المواد';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cover.variants', 'category']))
            ->defaultSort('updated_at', 'desc')
            // On a phone each post is one row: the picture and the title, then a line with the rest (theme.css).
            ->stackedOnMobile()
            ->extraAttributes(['class' => 'raqam-posts-list'])
            ->columns([
                // Its own link (cover-link.blade.php): a second link to the editor beside the title, kept from the keyboard
                // and from screen readers so the title is not read twice.
                ViewColumn::make('cover')->label('')->view('filament.columns.cover-link')->disabledClick()
                    ->state(fn (Post $record) => MediaThumb::url($record->cover))
                    ->viewData(['titleLinks' => true]),
                TextColumn::make('title')->label('العنوان')->wrap()->weight('medium')->limit(90)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('search_text ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])),
                TextColumn::make('kind_code')->label('النوع')->badge()->formatStateUsing(fn (string $state) => PostKind::names()[$state] ?? $state),
                TextColumn::make('category.name_ar')->label('التصنيف')->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success', 'scheduled' => 'info', 'archived' => 'gray', default => 'warning'
                    })
                    ->formatStateUsing(fn (string $state) => Post::STATUSES[$state] ?? $state),
                // A badge only when it is so: a «no» mark on every other row is noise, and an empty cell already says it.
                // Not red: red is left to a destructive action's confirmation.
                TextColumn::make('breaking')->label('عاجل')->badge()->color('warning')
                    ->state(fn (Post $record) => $record->isBreaking() ? 'عاجل' : null)->toggleable(),
                TextColumn::make('is_featured')->label('مميز')->badge()->color('info')
                    ->state(fn (Post $record) => $record->is_featured ? 'مميز' : null)->toggleable(),
                TextColumn::make('published_at')->label('النشر')->dateTime('Y-m-d H:i', 'Asia/Hebron')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(Post::STATUSES),
                SelectFilter::make('kind_code')->label('النوع')->options(fn () => PostKind::names()),
                SelectFilter::make('category_id')->label('التصنيف')->options(fn () => Category::query()->orderBy('sort_order')->pluck('name_ar', 'id')->all()),
                TernaryFilter::make('is_featured')->label('مميز'),
                Filter::make('breaking')->label('عاجل الآن')->toggle()->query(fn (Builder $query) => $query->where('breaking_until', '>', now())),
                Filter::make('sponsored')->label('ممول')->toggle()->query(fn (Builder $query) => $query->whereNotNull('sponsor_organization_id')),
            ])
            ->recordActions([
                EditAction::make()->label('تحرير'),
                Action::make('restore')->label('استرجاع')->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Post $record) => $record->trashed())
                    ->action(function (Post $record) {
                        app(PostSaver::class)->restore($record);
                        Notification::make()->success()->title('تم الاسترجاع.')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulk('archive', 'أرشفة', 'archived'),
                    self::bulk('unpublish', 'إلغاء النشر (مسودة)', 'draft'),
                    Destructive::quiet(BulkAction::make('trash'))->label('نقل إلى السلة')->requiresConfirmation()->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $records->each(fn (Post $post) => app(PostSaver::class)->trash($post));
                            Notification::make()->success()->title('نُقلت '.$records->count().' إلى السلة.')->send();
                        }),
                ]),
            ]);
    }

    /** Moves the selected items to a status one by one, through the same rules as the editor, and says which could not. */
    private static function bulk(string $name, string $label, string $status): BulkAction
    {
        return BulkAction::make($name)->label($label)->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($status) {
                $done = 0;
                $refused = [];

                foreach ($records as $post) {
                    try {
                        app(PostSaver::class)->save($post, [
                            'title' => $post->title, 'subtitle' => $post->subtitle, 'excerpt' => $post->excerpt, 'body' => $post->body, 'slug' => $post->slug,
                            'category_id' => $post->category_id, 'cover_media_id' => $post->cover_media_id, 'is_featured' => $post->is_featured,
                            'breaking_until' => $post->breaking_until, 'sponsor_organization_id' => $post->sponsor_organization_id,
                            'correction_note' => $post->correction_note, 'published_at' => $post->published_at, 'status' => $status,
                        ], Filament::auth()->id());
                        $done++;
                    } catch (PostRuleViolation $e) {
                        $refused[] = '«'.$post->title.'»: '.$e->getMessage();
                    }
                }

                Notification::make()->title($done.' تم تغييرها'.($refused ? '، و'.count($refused).' تعذّر' : '.'))
                    ->body(implode("\n", array_slice($refused, 0, 5)) ?: null)->color($refused ? 'warning' : 'success')->persistent($refused !== [])->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    protected static function globalSearchText(): string
    {
        return 'posts.search_text';
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['الحالة' => Post::STATUSES[$record->status] ?? $record->status];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
