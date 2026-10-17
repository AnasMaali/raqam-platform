<?php

namespace App\Filament\Resources\Posts;

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Organization;
use App\Models\PodcastEpisode;
use App\Models\PodcastSeason;
use App\Models\Post;
use App\Models\PostKind;
use App\Support\ArabicSlug;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Spike: one screen for a post and its subtype row (episode, success story). */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $modelLabel = 'منشور';

    protected static ?string $pluralModelLabel = 'المنشورات';

    /** Kinds this spike edits. */
    private const KINDS = ['episode', 'story'];

    public static function form(Schema $schema): Schema
    {
        $isDraft = fn (Get $get) => $get('status') === 'draft';

        return $schema->components([
            Section::make('المنشور')->columns(2)->schema([
                Select::make('kind_code')
                    ->label('النوع')
                    ->options(fn () => PostKind::whereIn('code', self::KINDS)->orderBy('sort_order')->pluck('name_ar', 'code'))
                    ->required()
                    ->live()
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'النوع لا يتغير بعد الإنشاء.' : null),
                Select::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name_ar')
                    ->searchable()
                    ->preload(),
                TextInput::make('title')
                    ->label('العنوان')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation) {
                        if ($operation === 'create' && blank($get('slug'))) {
                            $set('slug', ArabicSlug::make($state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('الرابط (slug)')
                    ->required()
                    ->maxLength(200)
                    ->dehydrateStateUsing(fn (?string $state) => ArabicSlug::make($state))
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                        if (ArabicSlug::make($value) === '') {
                            $fail('الرابط يجب أن يحتوي حروفًا أو أرقامًا.');
                        }
                    }),
                TextInput::make('subtitle')->label('العنوان الفرعي')->maxLength(255),
                Textarea::make('excerpt')->label('الملخص')->maxLength(500)->rows(3),
                RichEditor::make('body')
                    ->label('النص')
                    ->columnSpanFull()
                    ->required(fn (Get $get) => ! $isDraft($get))
                    ->dehydrateStateUsing(fn (?string $state) => $state ?? ''),
                Select::make('cover_media_id')
                    ->label('صورة الغلاف')
                    ->relationship('cover', 'original_name', fn ($query) => $query
                        ->where('mime_type', 'like', 'image/%')->where('visibility', 'public'))
                    ->searchable()
                    ->preload()
                    ->required(fn (Get $get) => ! $isDraft($get)),
                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة', 'scheduled' => 'مجدول', 'published' => 'منشور', 'archived' => 'مؤرشف',
                    ])
                    ->default('draft')
                    ->required()
                    ->live(),
                DateTimePicker::make('published_at')
                    ->label('وقت النشر')
                    ->required(fn (Get $get) => ! $isDraft($get)),
            ]),

            Section::make('بيانات الحلقة')->columns(2)
                ->visible(fn (Get $get) => $get('kind_code') === 'episode')
                ->schema([
                    Select::make('episode.season_id')
                        ->label('الموسم')
                        ->options(fn () => PodcastSeason::orderBy('season_number')->get()->mapWithKeys(fn (PodcastSeason $season) => [
                            $season->id => 'الموسم '.$season->season_number.($season->title_ar ? ' — '.$season->title_ar : ''),
                        ]))
                        ->required(fn (Get $get) => ! $isDraft($get)),
                    TextInput::make('episode.episode_number')
                        ->label('رقم الحلقة')
                        ->numeric()->minValue(1)->maxValue(32767)
                        ->required(fn (Get $get) => ! $isDraft($get))
                        ->rule(fn (Get $get, ?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                            $taken = PodcastEpisode::where('season_id', $get('episode.season_id'))
                                ->where('episode_number', $value)
                                ->when($record, fn ($query) => $query->where('post_id', '!=', $record->getKey()))
                                ->exists();

                            if ($taken) {
                                $fail('رقم الحلقة مستخدم في هذا الموسم.');
                            }
                        }),
                ]),

            Section::make('بيانات قصة النجاح')->columns(2)
                ->visible(fn (Get $get) => $get('kind_code') === 'story')
                ->schema([
                    Select::make('story.organization_id')
                        ->label('الشركة')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Organization::where('name', 'ilike', "%{$search}%")
                            ->orderBy('name')->limit(30)->pluck('name', 'id'))
                        ->getOptionLabelUsing(fn ($value) => Organization::find($value)?->name)
                        ->required(fn (Get $get) => ! $isDraft($get)),
                    TextInput::make('story.person_name')->label('اسم الشخص')->maxLength(120),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->limit(60),
                TextColumn::make('kind.name_ar')->label('النوع'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('published_at')->label('النشر')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make()]);
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
