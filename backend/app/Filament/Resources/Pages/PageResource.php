<?php

namespace App\Filament\Resources\Pages;

use App\Content\StaticPages;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Forms\SeoSection;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Support\ArabicSlug;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The static pages (decision 28): a text editor and the search fields. */
class PageResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'صفحة';

    protected static ?string $pluralModelLabel = 'الصفحات';

    protected static ?string $navigationLabel = 'الصفحات';

    protected static ?int $navigationSort = 11;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('العنوان')->required()->maxLength(160)->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $get('slug') ? null : $set('slug', ArabicSlug::make($state))),
            TextInput::make('slug')->label('الرابط')->maxLength(200)
                ->disabled(fn (?Page $record) => $record?->isSystem())->dehydrated(fn (?Page $record) => ! $record?->isSystem())
                ->helperText(fn (?Page $record) => $record?->isSystem() ? 'رابط ثابت تعتمد عليه روابط الموقع.' : 'يُنشأ من العنوان ويمكن تعديله.')
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                    StaticPages::isReserved(ArabicSlug::make((string) $value)) ? $fail('هذا الرابط محجوز لأقسام الموقع، اختر غيره.') : null;
                }),
            RichEditor::make('body')->label('النص')->columnSpanFull()
                ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3'], ['blockquote', 'bulletList', 'orderedList', 'table'], ['undo', 'redo']])
                ->extraInputAttributes(['style' => 'min-height: 22rem'])
                ->helperText('يُنصح بمراجعة محامٍ للصفحات القانونية (الخصوصية، الشروط، ملفات الارتباط).'),
            Toggle::make('is_published')->label('منشورة')->helperText('الصفحة غير المنشورة لا تظهر للزوار ولا في الخريطة.'),
            SeoSection::make('اتركها فارغة لاستعمال عنوان الصفحة.')->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('title')->label('الصفحة')->searchable(),
                TextColumn::make('slug')->label('الرابط')->formatStateUsing(fn (string $state) => '/'.$state)->extraAttributes(['dir' => 'ltr']),
                IconColumn::make('is_published')->label('منشورة')->boolean(),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i', 'Asia/Hebron'),
            ])
            ->recordActions([
                EditAction::make()->label('تحرير'),
                DeleteAction::make()->label('حذف')->visible(fn (Page $record) => ! $record->isSystem()),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->isSystem();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
