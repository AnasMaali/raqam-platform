<?php

namespace App\Filament\Resources\Posts;

use App\Content\Blocks\BlockRegistry;
use App\Content\Subtypes\SubtypeRegistry;
use App\Filament\Forms\MediaSelect;
use App\Filament\Forms\OrganizationSelect;
use App\Filament\Forms\SeoSection;
use App\Models\Category;
use App\Models\GuestRole;
use App\Models\Post;
use App\Models\Tag;
use App\Seo\SeoChecklist;
use App\Support\ArabicSlug;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/** The editor of one item (decisions 3, 21.6 – 21.10, 23.7): the text on the left, publishing and filing on the right. */
final class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Group::make(self::main())->columnSpan(['lg' => 2]),
                Group::make(self::side())->columnSpan(['lg' => 1]),
            ])->columnSpanFull(),
        ]);
    }

    /** @return list<Component> */
    private static function main(): array
    {
        return [
            Section::make()->schema([
                TextInput::make('title')->label('العنوان')->required()->maxLength(255)->live(onBlur: true),
                TextInput::make('subtitle')->label('العنوان الفرعي')->maxLength(255),
                TextInput::make('slug')->label('الرابط')->maxLength(200)->live(onBlur: true)
                    ->placeholder(fn (Get $get) => ArabicSlug::forTitle($get('title')))
                    ->helperText('يُنشأ من العنوان بدون الكلمات الزائدة. تعديله بعد النشر يحوّل الرابط القديم تلقائياً (301).'),
                Textarea::make('excerpt')->label('الملخص')->rows(3)->maxLength(500)->live(onBlur: true)
                    ->hint(fn (?string $state) => mb_strlen((string) $state).' / 500')->helperText('يظهر في القوائم ونتائج البحث.'),
                RichEditor::make('body')->label('النص')->live(onBlur: true)
                    ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3'], ['blockquote', 'bulletList', 'orderedList', 'table'], ['customBlocks'], ['undo', 'redo']])
                    ->customBlocks(app(BlockRegistry::class)->forEditor())
                    ->extraInputAttributes(['style' => 'min-height: 24rem'])
                    ->helperText('الصور والمعرض وبطاقة التوعية و«اقرأ أيضاً» من زر الإضافات في الشريط.'),
            ]),
            Section::make('معرض الصور')->collapsed()->schema([
                Repeater::make('gallery')->hiddenLabel()->schema([MediaSelect::make('media_id')->hiddenLabel()->required()])
                    ->reorderable()->defaultItems(0)->maxItems(40)->addActionLabel('إضافة صورة'),
            ]),
            ...self::kindSections(),
            Section::make('الضيوف')->visible(fn (?Post $record) => in_array($record?->kind_code, ['episode', 'roundtable'], true))->schema([
                Repeater::make('guests')->hiddenLabel()->defaultItems(0)->reorderable()->addActionLabel('إضافة ضيف')
                    ->schema([
                        Select::make('guest_id')->label('الضيف')->native(false)->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => DB::table('guests')->whereNull('deleted_at')->whereRaw('search_name ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])->limit(30)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value) => ctype_digit((string) $value) ? DB::table('guests')->where('id', (int) $value)->value('name') : null),
                        Select::make('role_code')->label('دوره')->native(false)->options(fn () => GuestRole::options())->required()->default('guest'),
                    ])->columns(2),
            ]),
            Section::make('تصحيح')->collapsed()->schema([
                Textarea::make('correction_note')->label('ملاحظة تصحيح')->rows(2)->maxLength(2000)
                    ->helperText('تظهر أسفل المادة بتاريخها (سياسة التصحيح).'),
            ]),
            SeoSection::make('اتركها فارغة لاستعمال عنوان المادة وملخصها.', titleFrom: 'title', descriptionFrom: 'excerpt'),
        ];
    }

    /** @return list<Component> */
    private static function side(): array
    {
        return [
            Section::make('النشر')->schema([
                Select::make('status')->label('الحالة')->native(false)->options(Post::STATUSES)->required()->live(),
                DateTimePicker::make('published_at')->label(fn (Get $get) => $get('status') === 'scheduled' ? 'وقت النشر' : 'تاريخ النشر')
                    ->seconds(false)->native(false)->displayFormat('Y-m-d H:i')
                    ->visible(fn (Get $get) => in_array($get('status'), ['scheduled', 'published', 'archived'], true))
                    ->required(fn (Get $get) => $get('status') === 'scheduled')
                    ->helperText(fn (Get $get) => $get('status') === 'scheduled' ? 'يُنشر تلقائياً في هذا الوقت (بتوقيت فلسطين).' : 'اتركه كما هو للحفاظ على تاريخ النشر.'),
                Toggle::make('is_featured')->label('مادة مميزة'),
                DateTimePicker::make('breaking_until')->label('عاجل حتى')->seconds(false)->native(false)->displayFormat('Y-m-d H:i')
                    ->helperText('اتركه فارغاً إن لم تكن عاجلة. يسقط العاجل تلقائياً عند هذا الوقت.'),
                OrganizationSelect::make('sponsor_organization_id')->label('محتوى ممول — الجهة الراعية')
                    ->helperText('تظهر علامة «ممول» واضحة، وتُضاف rel="sponsored" لروابطه.'),
            ]),
            Section::make('التصنيف والصورة')->schema([
                Select::make('category_id')->label('التصنيف')->native(false)->searchable()
                    ->options(fn () => Category::query()->orderByRaw('COALESCE(parent_id, id), parent_id NULLS FIRST, sort_order')->get()
                        ->mapWithKeys(fn (Category $c) => [$c->id => ($c->parent_id ? '↳ ' : '').$c->name_ar])->all())
                    ->helperText(fn (?Post $record) => DB::table('post_kinds')->where('code', $record?->kind_code)->value('requires_category') ? 'إجباري قبل النشر.' : 'اختياري لهذا النوع.'),
                MediaSelect::make('cover_media_id')->label('الصورة الرئيسية')->helperText('إجبارية قبل النشر. تُرفع من مكتبة الوسائط.'),
            ]),
            Section::make('الكتّاب والوسوم')->schema([
                Select::make('authors')->label('الكتّاب')->multiple()->native(false)->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('authors')->whereNull('deleted_at')->where('is_active', true)->whereRaw('search_name ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelsUsing(fn (array $values) => DB::table('authors')->whereIn('id', array_filter($values, fn ($v) => MediaSelect::isId($v)))->pluck('name', 'id')->all())
                    ->helperText('اتركه فارغاً لتظهر المادة باسم «فريق رَقَم». الترتيب كما تختارهم.'),
                TagsInput::make('tags')->label('الوسوم')->suggestions(fn () => Tag::query()->orderBy('name_ar')->limit(200)->pluck('name_ar')->all())
                    ->nestedRecursiveRules(['string', 'max:80'])->placeholder('اكتب وسماً واضغط Enter'),
            ]),
            Section::make('مساعد SEO')->collapsed(false)->schema([
                TextEntry::make('seo_assistant')->hiddenLabel()
                    ->state(function (Get $get, ?Post $record) {
                        $checks = app(SeoChecklist::class)->evaluate([
                            'title' => $get('title'), 'excerpt' => $get('excerpt'), 'body' => $get('body'), 'slug' => $get('slug') ?: ArabicSlug::forTitle($get('title')),
                            'tags' => $get('tags'), 'category_id' => $get('category_id'), 'cover_media_id' => $get('cover_media_id'), 'seo' => $get('seo'),
                            'category_required' => (bool) DB::table('post_kinds')->where('code', $record?->kind_code)->value('requires_category'),
                        ]);

                        return new HtmlString('<ul style="list-style:none;padding:0;margin:0;display:grid;gap:.4rem">'.collect($checks)->map(fn ($c) => '<li>'.($c['ok'] ? '✅' : '⚠️').' '.e($c['text']).'</li>')->implode('').'</ul>');
                    }),
            ]),
        ];
    }

    /** One section per kind that has facts of its own; only the one of this item's kind shows. */
    private static function kindSections(): array
    {
        $sections = [];

        foreach (app(SubtypeRegistry::class)->all() as $kind => $subtype) {
            $sections[] = Section::make('بيانات '.DB::table('post_kinds')->where('code', $kind)->value('name_ar'))
                ->statePath($kind)->columns(['default' => 1, 'md' => 2])
                ->visible(fn (?Post $record) => $record?->kind_code === $kind)
                ->schema($subtype->fields());
        }

        return $sections;
    }
}
