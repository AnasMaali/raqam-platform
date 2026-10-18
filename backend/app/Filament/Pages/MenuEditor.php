<?php

namespace App\Filament\Pages;

use App\Navigation\MenuStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Header and footer menus (decision 21.12): add links, drag them into order, nest one level.
 *
 * @property-read Schema $form
 */
class MenuEditor extends Page
{
    protected static ?string $slug = 'menus';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?string $navigationLabel = 'قوائم الموقع';

    protected static ?int $navigationSort = 33;

    protected static string|\UnitEnum|null $navigationGroup = 'الموقع';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $store = app(MenuStore::class);
        $state = [];
        foreach (array_keys($store->menus()) as $key) {
            $state[$key] = $store->load($key);
        }

        $this->form->fill($state);
    }

    public function save(): void
    {
        $store = app(MenuStore::class);
        $state = $this->form->getState();

        try {
            foreach (array_keys($store->menus()) as $key) {
                $store->save($key, $state[$key] ?? [], Filament::auth()->id());
            }
        } catch (ValidationException $e) {
            Notification::make()->danger()->persistent()->title('تعذّر الحفظ')->body(collect($e->errors())->flatten()->unique()->take(5)->implode(' — '))->send();

            return;
        }

        $this->mount();   // reload so new items carry their ids
        Notification::make()->success()->title('تم حفظ القوائم.')->send();
    }

    public function form(Schema $schema): Schema
    {
        $store = app(MenuStore::class);

        $tabs = [];
        foreach ($store->menus() as $key => $name) {
            $tabs[] = Tab::make($name)->schema([
                Repeater::make($key)->hiddenLabel()->schema(self::itemFields(withChildren: true))
                    ->reorderableWithDragAndDrop()->collapsible()->collapsed()->cloneable()
                    ->itemLabel(fn (array $state) => $state['label_ar'] ?? null)
                    ->addActionLabel('إضافة رابط')->defaultItems(0)->maxItems(MenuStore::MAX_ITEMS),
            ]);
        }

        return $schema->statePath('data')->components([Tabs::make('menus')->tabs($tabs)]);
    }

    /** @return list<Component> */
    private static function itemFields(bool $withChildren): array
    {
        $fields = [
            TextInput::make('id')->hidden()->dehydratedWhenHidden(),
            TextInput::make('label_ar')->label('الاسم الظاهر')->required()->maxLength(60),
            Select::make('type')->label('يفتح')->native(false)->required()->live()->options([
                'page' => 'صفحة', 'category' => 'تصنيف', 'section' => 'قسم خاص', 'url' => 'عنوان آخر',
            ]),
            Select::make('page_id')->label('الصفحة')->native(false)->searchable()
                ->options(fn () => DB::table('pages')->orderBy('title')->pluck('title', 'id')->all())
                ->visible(fn (Get $get) => $get('type') === 'page')->required(fn (Get $get) => $get('type') === 'page'),
            Select::make('category_id')->label('التصنيف')->native(false)->searchable()
                ->options(fn () => DB::table('categories')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                ->visible(fn (Get $get) => $get('type') === 'category')->required(fn (Get $get) => $get('type') === 'category'),
            Select::make('section_id')->label('القسم')->native(false)
                ->options(fn () => DB::table('sections')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                ->visible(fn (Get $get) => $get('type') === 'section')->required(fn (Get $get) => $get('type') === 'section'),
            TextInput::make('url')->label('العنوان')->maxLength(500)->extraInputAttributes(['dir' => 'ltr'])
                ->helperText('/about لصفحة من الموقع، أو https://... لموقع آخر.')
                ->visible(fn (Get $get) => $get('type') === 'url')->required(fn (Get $get) => $get('type') === 'url'),
            Toggle::make('opens_new_tab')->label('يفتح في نافذة جديدة'),
            Toggle::make('is_active')->label('ظاهر')->default(true),
        ];

        if ($withChildren) {
            $fields[] = Repeater::make('children')->label('روابط فرعية')->schema(self::itemFields(withChildren: false))
                ->reorderableWithDragAndDrop()->collapsible()->collapsed()
                ->itemLabel(fn (array $state) => $state['label_ar'] ?? null)
                ->addActionLabel('إضافة رابط فرعي')->defaultItems(0)->columnSpanFull();
        }

        return $fields;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('حفظ القوائم')->submit('save')])]),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'قوائم الموقع';
    }
}
