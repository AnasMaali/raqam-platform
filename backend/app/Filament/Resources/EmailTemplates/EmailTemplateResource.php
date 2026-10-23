<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Mail\EmailCatalog;
use App\Mail\EmailTemplates;
use App\Mail\TemplatedEmail;
use App\Models\EmailTemplate;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The texts of the system messages (20.7): subject and body of each, with variables such as {الاسم} and {الرمز}.
 * Values are escaped when a message is made; an unknown variable, or a missing required one, is refused on save.
 */
class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $slug = 'email-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $modelLabel = 'رسالة';

    protected static ?string $pluralModelLabel = 'قوالب الرسائل';

    protected static ?string $navigationLabel = 'قوالب الرسائل';

    protected static string|\UnitEnum|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 80;

    protected static bool $isGloballySearchable = false;

    /** Only keys of the catalogue reach the database; anything else in the address is simply not found. */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (! array_key_exists((string) $key, EmailCatalog::templates())) {
            return null;
        }
        EmailTemplates::ensure((string) $key);

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (?EmailTemplate $record) => $record ? self::variablesHelp($record->key) : '')->columnSpanFull(),
            TextInput::make('subject')->label('عنوان الرسالة')->required()->maxLength(200)->columnSpanFull(),
            RichEditor::make('body_html')->label('نص الرسالة')->required()->columnSpanFull()
                ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->extraInputAttributes(['style' => 'min-height: 16rem'])
                ->helperText('يُوضع النص داخل إطار رَقَم تلقائياً، وتُرسل معه نسخة نص عادي.'),
            Toggle::make('is_active')->label('مفعّلة')
                ->disabled(fn (?EmailTemplate $record) => $record !== null && EmailCatalog::isLocked($record->key))
                ->helperText(fn (?EmailTemplate $record) => $record !== null && EmailCatalog::isLocked($record->key)
                    ? 'رسالة أمان أو رمز دخول: لا يمكن إيقافها.' : 'الرسالة المطفأة لا تُرسل.'),
        ]);
    }

    public static function variablesHelp(string $key): string
    {
        $vars = array_map(fn ($name) => '{'.$name.'}'.(in_array($name, EmailCatalog::required($key), true) ? ' (إجباري)' : ''), array_keys(EmailCatalog::variables($key)));

        return 'المتغيرات المتاحة: '.implode('، ', $vars).'. تُكتب كما هي بين قوسين { } وتُستبدل بقيمها عند الإرسال.';
    }

    /** @return array<string, string> made-up values for a preview or a test message */
    public static function sample(string $key): array
    {
        $samples = ['الرمز' => '123456', 'الدقائق' => '10', 'الاسم' => 'سامي', 'البريد' => 'sami@example.com', 'الهاتف' => '+970599000000',
            'الموضوع' => 'استفسار', 'الرسالة' => "مرحباً،\nهذه رسالة تجريبية.", 'الرابط' => url('/'), 'الوقت' => now('Asia/Hebron')->format('Y-m-d H:i'),
            'المتصفح' => 'Chrome', 'النظام' => 'Android', 'المصدر' => 'مصدر العملات', 'المرات' => '3', 'الخطأ' => 'انتهت مهلة الاتصال.',
            'الفيديو' => 'حلقة تجريبية', 'الحالة' => 'صار خاصاً', 'المواد' => 'خبر تجريبي', 'المهمة' => 'currency:fetch', 'المساحة' => '1.5 GB',
            'النسبة' => '8.0%', 'الحملة' => 'حملة تجريبية', 'المعلن' => 'شركة تجريبية', 'النهاية' => now('Asia/Hebron')->addDays(3)->format('Y-m-d H:i'),
            'الفترة' => now('Asia/Hebron')->subWeek()->format('Y-m-d').' – '.now('Asia/Hebron')->format('Y-m-d'), 'الملخص' => '<p>هنا يظهر ملخص الأسبوع.</p>',
            'الخدمة' => 'حلقة بودكاست', 'المرجع' => 'RQ-2026-00001', 'الفاتورة' => 'INV-2026-00001'];

        return array_intersect_key($samples, EmailCatalog::variables($key));
    }

    public static function sampleEmail(string $key): TemplatedEmail
    {
        return new TemplatedEmail($key, self::sample($key));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('key', array_keys(EmailCatalog::templates())))
            ->defaultSort('audience')
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->label('الرسالة')->searchable(),
                TextColumn::make('audience')->label('إلى')->badge()->formatStateUsing(fn (string $state) => EmailCatalog::AUDIENCES[$state] ?? $state),
                TextColumn::make('subject')->label('العنوان')->limit(50)->toggleable(),
                IconColumn::make('is_active')->label('مفعّلة')->boolean(),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i', 'Asia/Hebron')->toggleable(),
            ])
            ->filters([SelectFilter::make('audience')->label('إلى')->options(EmailCatalog::AUDIENCES)])
            ->recordActions([EditAction::make()->label('تعديل')])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
