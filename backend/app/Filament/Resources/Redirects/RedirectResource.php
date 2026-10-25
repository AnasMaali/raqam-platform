<?php

namespace App\Filament\Resources\Redirects;

use App\Filament\Actions\BulkRecords;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Models\Redirect;
use App\Support\SafeUrl;
use BackedEnum;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Redirects and "gone" answers (decisions 23.2, 23.6): made automatically for posts, added by hand for anything else. */
class RedirectResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static ?string $modelLabel = 'تحويل';

    protected static ?string $pluralModelLabel = 'التحويلات';

    protected static ?string $navigationLabel = 'التحويلات';

    protected static ?int $navigationSort = 37;

    protected static string|\UnitEnum|null $navigationGroup = 'الموقع';

    public const STATUSES = [301 => 'دائم (301)', 302 => 'مؤقت (302)', 410 => 'محذوف نهائياً (410)'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_path')->label('العنوان القديم')->required()->maxLength(500)->extraInputAttributes(['dir' => 'ltr'])
                ->helperText('يبدأ بـ / ، مثال: /news/15/عنوان-قديم')
                ->unique(ignoreRecord: true)->validationMessages(['unique' => 'يوجد تحويل لهذا العنوان.'])
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                    SafeUrl::isPath((string) $value) ? null : $fail('العنوان يجب أن يبدأ بـ / ولا يحتوي فراغات.');
                })
                ->dehydrateStateUsing(fn (?string $state) => trim((string) $state)),
            Select::make('status_code')->label('النوع')->native(false)->options(self::STATUSES)->default(301)->required()->live(),
            TextInput::make('to_path')->label('العنوان الجديد')->maxLength(500)->extraInputAttributes(['dir' => 'ltr'])
                ->helperText('/مسار داخل الموقع أو https://...')
                ->visible(fn (Get $get) => (int) $get('status_code') !== 410)
                ->required(fn (Get $get) => (int) $get('status_code') !== 410)
                ->rule(fn (Get $get, ?Redirect $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                    if ((int) $get('status_code') === 410 || $value === null || $value === '') {
                        return;
                    }
                    if (! SafeUrl::isSafe((string) $value)) {
                        $fail('العنوان يجب أن يبدأ بـ / أو https:// ولا يحتوي فراغات.');
                    } elseif ($value === $get('from_path')) {
                        $fail('العنوان الجديد هو نفسه القديم.');
                    } elseif (Redirect::query()->where('from_path', $value)->when($record, fn (Builder $q) => $q->whereKeyNot($record->id))->exists()) {
                        $fail('العنوان الجديد نفسه محوَّل إلى مكان آخر؛ حوِّل إلى الوجهة النهائية مباشرة.');
                    }
                }),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('from_path')->label('القديم')->searchable()->limit(60)->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('to_path')->label('الجديد')->placeholder('—')->searchable()->limit(60)->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('status_code')->label('النوع')->badge()->formatStateUsing(fn (int $state) => self::STATUSES[$state] ?? $state),
                TextColumn::make('hits')->label('الزيارات')->numeric()->sortable(),
                TextColumn::make('last_hit_at')->label('آخر زيارة')->dateTime('Y-m-d H:i', 'Asia/Hebron')->placeholder('—')->sortable(),
            ])
            ->filters([SelectFilter::make('status_code')->label('النوع')->options(self::STATUSES)])
            ->toolbarActions([BulkRecords::delete()])
            ->recordActions([
                EditAction::make()->label('تعديل')->mutateDataUsing(fn (array $data) => (int) $data['status_code'] === 410 ? [...$data, 'to_path' => null] : $data),
                DeleteAction::make()->label('حذف'),
            ])
            ->headerActions([
                CreateAction::make()->label('تحويل جديد')
                    // Opened from the missing pages screen (?tableAction=create&tableActionArguments[from]=…) with that address.
                    ->fillForm(fn (array $arguments) => ['status_code' => 301]
                        + (is_string($arguments['from'] ?? null) && SafeUrl::isPath($arguments['from']) ? ['from_path' => $arguments['from']] : []))
                    ->mutateDataUsing(function (array $data) {
                        if ((int) $data['status_code'] === 410) {
                            $data['to_path'] = null;
                        }

                        return $data + ['created_by' => Filament::auth()->id()];
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRedirects::route('/')];
    }
}
