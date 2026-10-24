<?php

namespace App\Filament\Pages;

use App\Navigation\HomeLayout;
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
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The order of the home page (decision 21.11): drag the blocks into place (or move them with the arrows on a phone),
 * show or hide each, pick its category, section, playlist or ad slot. Saving refreshes the site at once.
 *
 * @property-read Schema $form
 */
class HomeLayoutEditor extends Page
{
    protected static ?string $slug = 'home-layout';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'ترتيب الرئيسية';

    protected static ?int $navigationSort = 32;

    protected static string|\UnitEnum|null $navigationGroup = 'الموقع';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** True while the page shows the suggested order, before it was ever saved. */
    public bool $suggested = false;

    public function mount(): void
    {
        $layout = app(HomeLayout::class);
        $blocks = $layout->load();
        $this->suggested = $blocks === [];
        $this->form->fill(['blocks' => $this->suggested ? $layout->defaults() : $blocks]);
    }

    public function save(): void
    {
        try {
            app(HomeLayout::class)->save($this->form->getState()['blocks'] ?? [], Filament::auth()->id());
        } catch (ValidationException $e) {
            Notification::make()->danger()->persistent()->title('تعذّر الحفظ')->body(collect($e->errors())->flatten()->unique()->take(5)->implode(' — '))->send();

            return;
        }

        $this->mount();   // reload so new blocks carry their ids
        Notification::make()->success()->title('تم حفظ ترتيب الرئيسية، وأُبلغ الموقع.')->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Repeater::make('blocks')->hiddenLabel()
                ->schema([
                    TextInput::make('id')->hidden()->dehydratedWhenHidden(),
                    Select::make('block_type')->label('النوع')->native(false)->required()->live()->options(HomeLayout::TYPES),
                    Select::make('category_id')->label('التصنيف')->native(false)->searchable()
                        ->options(fn () => DB::table('categories')->orderBy('sort_order')->orderBy('name_ar')->pluck('name_ar', 'id')->all())
                        ->visible(fn (Get $get) => $get('block_type') === 'category')->required(fn (Get $get) => $get('block_type') === 'category'),
                    Select::make('section_id')->label('القسم الخاص')->native(false)
                        ->options(fn () => DB::table('sections')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                        ->visible(fn (Get $get) => $get('block_type') === 'section')->required(fn (Get $get) => $get('block_type') === 'section'),
                    Select::make('playlist_id')->label('قائمة التشغيل')->native(false)->searchable()
                        ->options(fn () => DB::table('playlists')->orderBy('sort_order')->orderBy('name_ar')->pluck('name_ar', 'id')->all())
                        ->visible(fn (Get $get) => $get('block_type') === 'playlist')->required(fn (Get $get) => $get('block_type') === 'playlist'),
                    Select::make('placement_code')->label('مكان الإعلان')->native(false)
                        ->options(fn () => DB::table('ad_placements')->whereIn('code', HomeLayout::AD_SLOTS)->pluck('name_ar', 'code')->all())
                        ->visible(fn (Get $get) => $get('block_type') === 'ad')->required(fn (Get $get) => $get('block_type') === 'ad'),
                    TextInput::make('title_ar')->label('العنوان الظاهر')->maxLength(80)->placeholder('اسم البلوك نفسه'),
                    TextInput::make('item_limit')->label('عدد العناصر')->integer()->minValue(1)->maxValue(24)
                        ->placeholder(fn (Get $get) => (string) (HomeLayout::DEFAULT_LIMIT[$get('block_type')] ?? '—'))
                        ->visible(fn (Get $get) => isset(HomeLayout::DEFAULT_LIMIT[$get('block_type')])),
                    Toggle::make('is_active')->label('ظاهر')->default(true),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->reorderableWithDragAndDrop()->reorderableWithButtons()->collapsible()->collapsed()
                ->itemLabel(fn (array $state) => self::label($state))
                ->addActionLabel('إضافة بلوك')->defaultItems(0)->maxItems(HomeLayout::MAX_BLOCKS),
        ]);
    }

    /** "قسم تصنيف: اقتصاد (مخفي)" — what a collapsed block says about itself. */
    private static function label(array $state): string
    {
        $type = HomeLayout::TYPES[$state['block_type'] ?? ''] ?? 'بلوك جديد';
        $target = match ($state['block_type'] ?? null) {
            'category' => DB::table('categories')->where('id', (int) ($state['category_id'] ?? 0))->value('name_ar'),
            'section' => DB::table('sections')->where('id', (int) ($state['section_id'] ?? 0))->value('name_ar'),
            'playlist' => DB::table('playlists')->where('id', (int) ($state['playlist_id'] ?? 0))->value('name_ar'),
            'ad' => DB::table('ad_placements')->where('code', (string) ($state['placement_code'] ?? ''))->value('name_ar'),
            default => null,
        };
        $title = trim((string) ($state['title_ar'] ?? ''));

        return $type.($target ? ': '.$target : '').($title !== '' ? ' — «'.$title.'»' : '').(($state['is_active'] ?? true) ? '' : ' (مخفي)');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('حفظ الترتيب')->submit('save')])]),
        ]);
    }

    public function getSubheading(): ?string
    {
        return $this->suggested ? 'هذا ترتيب مقترح لم يُحفظ بعد؛ عدّله ثم احفظه.' : 'اسحب البلوكات لترتيبها، أو استعمل الأسهم على الجوال.';
    }

    public function getTitle(): string|Htmlable
    {
        return 'ترتيب الرئيسية';
    }
}
