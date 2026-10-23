<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Faqs\Pages\CreateFaq;
use App\Filament\Resources\Faqs\Pages\EditFaq;
use App\Filament\Resources\Faqs\Pages\ListFaqs;
use App\Models\Faq;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The FAQ page (28): questions in order; each published one also answers in the chatbot (18.4). */
class FaqResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Faq::class;

    protected static ?string $slug = 'faqs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $modelLabel = 'سؤال';

    protected static ?string $pluralModelLabel = 'الأسئلة الشائعة';

    protected static ?string $navigationLabel = 'الأسئلة الشائعة';

    protected static string|\UnitEnum|null $navigationGroup = 'الشات بوت';

    protected static ?int $navigationSort = 74;

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('service_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('question')->label('السؤال')->required()->maxLength(300)->columnSpanFull(),
            Textarea::make('answer')->label('الجواب')->required()->rows(5)->maxLength(5000)->columnSpanFull()->helperText('نص عادي؛ يظهر في صفحة الأسئلة الشائعة وفي الشات بوت.'),
            Toggle::make('is_published')->label('منشور')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')->defaultSort('sort_order')
            ->columns([
                TextColumn::make('question')->label('السؤال')->searchable()->wrap(),
                IconColumn::make('is_published')->label('منشور')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل'), DeleteAction::make()->label('حذف')->modalDescription('يُحذف من صفحة الأسئلة الشائعة ومن الشات بوت.')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFaqs::route('/'), 'create' => CreateFaq::route('/create'), 'edit' => EditFaq::route('/{record}/edit')];
    }
}
