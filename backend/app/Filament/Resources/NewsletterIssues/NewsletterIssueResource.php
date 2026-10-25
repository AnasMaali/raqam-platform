<?php

namespace App\Filament\Resources\NewsletterIssues;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\NewsletterIssues\Pages\EditNewsletterIssue;
use App\Filament\Resources\NewsletterIssues\Pages\ListNewsletterIssues;
use App\Models\NewsletterIssue;
use App\Models\Post;
use App\Newsletter\NewsletterIssues;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The weekly newsletters (19.6, 19.10): the draft made on the admin's day, its posts and words, Brevo's numbers.
 * Sending happens in Brevo, by the admin; there is no send button here on purpose.
 */
class NewsletterIssueResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = NewsletterIssue::class;

    protected static ?string $slug = 'newsletter-issues';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $modelLabel = 'نشرة';

    protected static ?string $pluralModelLabel = 'النشرات الأسبوعية';

    protected static ?string $navigationLabel = 'النشرات الأسبوعية';

    protected static string|\UnitEnum|null $navigationGroup = 'التواصل';

    protected static ?int $navigationSort = 72;

    protected static bool $isGloballySearchable = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (?NewsletterIssue $record) => $record?->status === 'sent'
                ? 'أُرسلت هذه النشرة من Brevo؛ لا يمكن تعديلها.'
                : 'التعديل هنا يُحدّث المسودة في Brevo أيضاً. الإرسال من Brevo نفسه بعد المراجعة.')->columnSpanFull(),
            TextInput::make('subject')->label('عنوان الرسالة')->required()->maxLength(200)->columnSpanFull(),
            Textarea::make('intro')->label('المقدمة')->rows(3)->maxLength(2000)->columnSpanFull(),
            Repeater::make('posts')->label('المواد (بالترتيب)')->reorderable()->minItems(1)->maxItems(20)->columnSpanFull()
                ->simple(Select::make('post_id')->label('المادة')->required()->native(false)->searchable()
                    ->options(fn () => Post::query()->live()->where('published_at', '>', now()->subDays(60))->orderByDesc('published_at')->limit(200)->pluck('title', 'id')->all())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect(['newsletter_issues.*', 'post_total' => DB::table('newsletter_issue_posts')->selectRaw('count(*)')->whereColumn('issue_id', 'newsletter_issues.id')]))
            ->defaultSort('week_start', 'desc')
            ->columns([
                TextColumn::make('week_start')->label('الأسبوع')->date('Y-m-d'),
                TextColumn::make('subject')->label('العنوان')->limit(60)->searchable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'sent_to_brevo' => 'warning', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state) => NewsletterIssues::STATUSES[$state] ?? $state),
                TextColumn::make('post_total')->label('المواد'),
                // Only numbers already fetched (hourly): the list never waits for Brevo.
                TextColumn::make('opens')->label('فتح / نقر')->state(function (NewsletterIssue $r) {
                    $s = Cache::get('newsletter:stats:'.$r->id);

                    return $s ? $s['opens'].' / '.$s['clicks'] : '—';
                }),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(NewsletterIssues::STATUSES)])
            ->recordActions([EditAction::make()->label('مراجعة')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsletterIssues::route('/'),
            'edit' => EditNewsletterIssue::route('/{record}/edit'),
        ];
    }
}
