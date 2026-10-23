<?php

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The messages of the contact form (20.13), read only: opening one marks it read; it can be archived or deleted. The
 * reply is written in the admin's own mailbox (the button opens it with the address and subject filled in).
 */
class ContactMessageResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = ContactMessage::class;

    protected static ?string $slug = 'contact-messages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $modelLabel = 'رسالة';

    protected static ?string $pluralModelLabel = 'رسائل «اتصل بنا»';

    protected static ?string $navigationLabel = 'رسائل «اتصل بنا»';

    protected static string|\UnitEnum|null $navigationGroup = 'التواصل';

    protected static ?int $navigationSort = 70;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationBadge(): ?string
    {
        $new = ContactMessage::query()->where('status', 'new')->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المرسل')->columns(2)->schema([
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('email')->label('البريد')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('phone')->label('الهاتف')->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('created_at')->label('وصلت')->dateTime('Y-m-d H:i', 'Asia/Hebron'),
                TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn (string $state) => ContactMessage::STATUSES[$state] ?? $state),
            ]),
            Section::make('الرسالة')->schema([
                TextEntry::make('subject')->label('الموضوع'),
                // Plain text with its line breaks; escaped like every visitor's text.
                TextEntry::make('message')->label('النص')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('وصلت')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('name')->label('الاسم')->searchable()->weight(fn (ContactMessage $r) => $r->status === 'new' ? 'bold' : null),
                TextColumn::make('subject')->label('الموضوع')->searchable()->limit(60),
                TextColumn::make('email')->label('البريد')->searchable()->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'new' => 'warning', 'read' => 'success', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state) => ContactMessage::STATUSES[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(ContactMessage::STATUSES),
            ])
            ->recordActions([
                ViewAction::make()->label('فتح'),
                self::archiveAction(),
                DeleteAction::make()->label('حذف')->modalDescription('تُحذف الرسالة وبيانات المرسل نهائياً.'),
            ])
            ->toolbarActions([
                BulkAction::make('archive')->label('أرشفة المحدد')->icon('heroicon-o-archive-box')->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn (ContactMessage $m) => self::mark($m, 'archived'))),
                DeleteBulkAction::make()->label('حذف المحدد'),
            ]);
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')->label('أرشفة')->icon('heroicon-o-archive-box')
            ->visible(fn (ContactMessage $record) => $record->status !== 'archived')
            ->action(fn (ContactMessage $record) => self::mark($record, 'archived'));
    }

    public static function mark(ContactMessage $message, string $status): void
    {
        $message->fill(['status' => $status, 'handled_by' => auth()->id(), 'handled_at' => now()])->save();
    }

    /** The admin's own mailbox, with the address and "Re:" subject filled in; both are encoded, so nothing can be added to the link. */
    public static function replyUrl(ContactMessage $message): string
    {
        return 'mailto:'.rawurlencode($message->email).'?subject='.rawurlencode('رد: '.$message->subject);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
            'view' => ViewContactMessage::route('/{record}'),
        ];
    }
}
