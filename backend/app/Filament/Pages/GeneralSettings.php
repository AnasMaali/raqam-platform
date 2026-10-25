<?php

namespace App\Filament\Pages;

use App\Filament\Forms\MediaSelect;
use App\Settings\SettingsCatalog;
use App\Settings\SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * General settings (21.13): name, contact, WhatsApp, social accounts and the default SEO text.
 *
 * @property-read Schema $form
 */
class GeneralSettings extends Page
{
    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'الإعدادات العامة';

    protected static ?int $navigationSort = 80;

    protected static string|\UnitEnum|null $navigationGroup = 'الموقع';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $store = app(SettingsStore::class);
        $state = [];
        $fields = SettingsCatalog::fields();
        foreach ($store->values() as $key => $value) {
            $state[$store->fieldName($key)] = $fields[$key]['type'] === 'boolean' ? ($value ?? $fields[$key]['default'] ?? 'false') === 'true' : $value;
        }
        $state['social'] = $store->socialLinks();

        $this->form->fill($state);
    }

    public function save(): void
    {
        $store = app(SettingsStore::class);
        $state = $this->form->getState();

        try {
            $store->save($state, $state['social'] ?? [], Filament::auth()->id());
        } catch (ValidationException $e) {
            // Field errors show under their fields; anything else (a rule only the database knew) shows here.
            $general = $e->errors()['settings'] ?? null;
            if ($general !== null) {
                Notification::make()->danger()->title($general[0])->send();

                return;
            }
            throw $e;
        }

        Notification::make()->success()->title('تم حفظ الإعدادات.')->send();
    }

    public function form(Schema $schema): Schema
    {
        $store = app(SettingsStore::class);
        $groups = [];

        foreach (SettingsCatalog::fields() as $key => $field) {
            $name = $store->fieldName($key);
            $input = match (true) {
                isset($field['options']) => Select::make($name)->options($field['options'])->native(false),
                default => match ($field['type']) {
                    'boolean' => Toggle::make($name),
                    'text' => Textarea::make($name)->rows(3)->maxLength(2000),
                    'media' => MediaSelect::make($name),
                    default => TextInput::make($name)->maxLength(255),
                },
            };

            $input->label($field['label'])->helperText($field['hint'] ?? null)->validationMessages(SettingsCatalog::messages($name));
            // A switch holds true/false in the form; the store turns it into the words the table keeps.
            if ($field['type'] !== 'boolean') {
                $input->rules(SettingsCatalog::rules($field['type']));
            }

            match ($field['type']) {
                'email' => $input->email()->inputMode('email')->extraInputAttributes(['dir' => 'ltr']),
                'phone' => $input->tel()->extraInputAttributes(['dir' => 'ltr']),
                'url' => $input->url()->extraInputAttributes(['dir' => 'ltr']),
                default => null,
            };

            $groups[$field['group']][] = $input;
        }

        $sections = [];
        foreach ($groups as $title => $inputs) {
            $sections[] = Section::make($title)->columns(['default' => 1, 'md' => 2])->schema($inputs);
        }

        $social = [];
        foreach ($store->socialPlatforms() as $code => $name) {
            $social[] = TextInput::make("social.{$code}")->label($name)->url()->maxLength(2048)
                ->extraInputAttributes(['dir' => 'ltr'])->placeholder('https://')
                ->rules(['nullable', 'url:http,https'])
                ->validationMessages(['url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://']);
        }
        $sections[] = Section::make('حسابات التواصل الاجتماعي')->description('اترك الحقل فارغاً لإخفاء الحساب.')
            ->columns(['default' => 1, 'md' => 2])->schema($social);

        return $schema->statePath('data')->components($sections);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('حفظ')->submit('save')])]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'الإعدادات العامة';
    }
}
