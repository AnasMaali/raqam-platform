<?php

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Eras\Pages\ManageEras;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Era;
use App\Models\Section;
use App\Models\Tag;
use App\Models\User;
use App\Support\Deletion;
use App\Support\UniqueSlug;
use App\Taxonomy\TagService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A page whose save fails with something the form must not dress up as a data problem. */
class LostConnectionBase
{
    protected function handleRecordCreation(array $data): Model
    {
        $pdo = new PDOException('connection lost');
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '08006');

        throw new QueryException('pgsql', 'insert', [], $pdo);
    }
}

class LostConnectionPage extends LostConnectionBase
{
    use ReportsDatabaseRefusals;

    public function halt(): never
    {
        throw new Halt;
    }

    public function save(): mixed
    {
        return $this->handleRecordCreation([]);
    }
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
});

// ---- slugs ---------------------------------------------------------------------------------------

test('a slug that is taken gets -2, -3 ...', function () {
    DB::table('tags')->insert([['name_ar' => 'نفط', 'slug' => 'نفط'], ['name_ar' => 'نفط2', 'slug' => 'نفط-2']]);

    expect(UniqueSlug::for('tags', 'نفط'))->toBe('نفط-3')
        ->and(UniqueSlug::for('tags', 'غاز'))->toBe('غاز')
        ->and(UniqueSlug::for('tags', 'نفط', ignoreId: DB::table('tags')->where('slug', 'نفط')->value('id')))->toBe('نفط');
});

test('a name with no usable letters falls back to a placeholder instead of an empty slug', function () {
    expect(UniqueSlug::for('tags', '؟؟!!', fallback: 'tag'))->toBe('tag');
});

test('a very long slug keeps its suffix within the column', function () {
    $long = str_repeat('ا', 300);
    DB::table('tags')->insert(['name_ar' => 'x', 'slug' => mb_substr($long, 0, 200)]);

    $slug = UniqueSlug::for('tags', $long);

    expect(mb_strlen($slug))->toBeLessThanOrEqual(200)->and($slug)->toEndWith('-2');
});

// ---- categories ----------------------------------------------------------------------------------

test('the six starting categories are there, in order', function () {
    expect(Category::orderBy('sort_order')->pluck('name_ar')->all())
        ->toBe(['محلي (نابلس)', 'فلسطين', 'أسواق ومال', 'أعمال وشركات', 'طاقة', 'تكنولوجيا']);
});

test('a category is created with a slug made from its name, and goes last', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm(['name_ar' => 'الاقتصاد الرقمي', 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();

    $category = Category::where('name_ar', 'الاقتصاد الرقمي')->sole();
    expect($category->slug)->toBe('الاقتصاد-الرقمي')->and($category->sort_order)->toBe(7)->and($category->parent_id)->toBeNull();
});

test('the slug can be edited and is cleaned of anything the address cannot hold', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm(['name_ar' => 'تصنيف', 'slug' => 'مخصص / بعلامات؟ #', 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();

    expect(Category::where('name_ar', 'تصنيف')->value('slug'))->toBe('مخصص-بعلامات');
});

test('two categories with the same name get different slugs', function () {
    $parent = Category::where('slug', 'طاقة')->first();
    foreach ([null, $parent->id] as $parentId) {
        Livewire::test(CreateCategory::class)->fillForm(['name_ar' => 'طاقة', 'parent_id' => $parentId, 'is_active' => true])->call('create');
    }

    expect(Category::where('name_ar', 'طاقة')->pluck('slug')->sort()->values()->all())->toBe(['طاقة', 'طاقة-2'])
        ->and(Category::where('slug', 'طاقة-2')->value('parent_id'))->toBe($parent->id);
});

test('the same name at the same level is refused with a message', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm(['name_ar' => 'طاقة', 'is_active' => true])
        ->call('create')->assertHasFormErrors(['name_ar']);

    expect(Category::where('name_ar', 'طاقة')->count())->toBe(1);
});

test('a sub-category can be added under a main one, but not under another sub-category', function () {
    $main = Category::where('slug', 'فلسطين')->first();
    Livewire::test(CreateCategory::class)->fillForm(['name_ar' => 'القدس', 'parent_id' => $main->id, 'is_active' => true])->call('create')->assertHasNoFormErrors();
    $sub = Category::where('name_ar', 'القدس')->first();
    expect($sub->parent_id)->toBe($main->id);

    // The form only offers main categories; a forged request is still stopped by the database and explained.
    Livewire::test(CreateCategory::class)
        ->fillForm(['name_ar' => 'البلدة القديمة', 'is_active' => true])
        ->set('data.parent_id', $sub->id)
        ->call('create')->assertHasFormErrors(['parent_id']);

    expect(Category::where('name_ar', 'البلدة القديمة')->exists())->toBeFalse();
});

test('when the database refuses a save the form is left as it was and the reason is given in Arabic', function (array $data, string $why) {
    // Past the form's own checks (a race between two editors, say) only the database can say no.
    $sub = Category::create(['name_ar' => 'غزة', 'slug' => 'غزة', 'parent_id' => Category::where('slug', 'فلسطين')->value('id')]);
    $page = new CreateCategory;
    $values = array_merge(['name_ar' => 'جديد', 'slug' => 'new', 'parent_id' => $sub->id], $data);

    // What the database says, read back the way the page reads it...
    $refusal = null;
    try {
        DB::transaction(fn () => Category::create($values));
    } catch (QueryException $e) {
        $refusal = $e;
    }
    expect($refusal)->not->toBeNull()->and($page->refusalMessage($refusal))->toContain($why);

    // ...and the page stops the save and leaves nothing behind.
    $create = new ReflectionMethod($page, 'handleRecordCreation');
    expect(fn () => $create->invoke($page, $values))->toThrow(Halt::class);
    expect(Category::where('name_ar', 'جديد')->exists())->toBeFalse();
})->with([
    'a sub-category under a sub-category' => [[], 'المستوى الفرعي واحد فقط'],
    'a parent that was deleted meanwhile' => [['parent_id' => 987654], 'لم يعد موجوداً'],
    'an address that was taken meanwhile' => [['parent_id' => null, 'slug' => 'طاقة'], 'مستعملة مسبقاً'],
    'text where a number belongs' => [['sort_order' => 'abc'], 'غير صالحة'],
]);

test('refusals that are not about the data are not hidden behind a friendly message', function () {
    $other = new QueryException('pgsql', 'select 1', [], tap(new PDOException('down'), fn ($p) => (new ReflectionProperty(Exception::class, 'code'))->setValue($p, '08006')));

    expect((new CreateCategory)->refusalMessage($other))->toBeNull();
});

test('a database error that is not about the data is not hidden by the form', function () {
    expect(fn () => (new LostConnectionPage)->save())->toThrow(QueryException::class, 'connection lost');
});

test('a record that is deleted while something points at it says so, and other database errors are not hidden', function () {
    $record = new class extends Category
    {
        public function delete()
        {
            $pdo = new PDOException('boom');
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, 'XX000');

            throw new QueryException('pgsql', 'delete', [], $pdo);
        }
    };

    expect(fn () => Deletion::attempt($record, 'س', 'مواد'))->toThrow(QueryException::class);
});

test('the choice of parent never offers a sub-category or the category itself', function () {
    $main = Category::where('slug', 'فلسطين')->first();
    $other = Category::where('slug', 'طاقة')->first();
    $sub = Category::create(['name_ar' => 'غزة', 'slug' => 'غزة', 'parent_id' => $main->id]);
    $parentField = fn (Category $record) => collect(Livewire::test(EditCategory::class, ['record' => $record->id])->instance()->getSchema('form')->getFlatFields())
        ->first(fn ($f) => $f->getName() === 'parent_id');

    $offeredToOther = $parentField($other)->getOptions();
    expect($offeredToOther)->toHaveKey($main->id)->not->toHaveKey($other->id)->not->toHaveKey($sub->id);

    expect($parentField($sub)->getOptions())->toHaveKey($main->id)->not->toHaveKey($sub->id);
});

test('a category that has sub-categories has its parent choice locked, one without is free', function () {
    $main = Category::where('slug', 'فلسطين')->first();
    Category::create(['name_ar' => 'غزة', 'slug' => 'غزة', 'parent_id' => $main->id]);
    $free = Category::where('slug', 'طاقة')->first();
    $parentField = fn (Category $record) => collect(Livewire::test(EditCategory::class, ['record' => $record->id])->instance()->getSchema('form')->getFlatFields())
        ->first(fn ($f) => $f->getName() === 'parent_id');

    expect($parentField($main)->isDisabled())->toBeTrue()->and($parentField($free)->isDisabled())->toBeFalse();
});

test('a category that has sub-categories cannot be made a sub-category', function () {
    $main = Category::where('slug', 'فلسطين')->first();
    Category::create(['name_ar' => 'غزة', 'slug' => 'غزة', 'parent_id' => $main->id]);
    $other = Category::where('slug', 'طاقة')->first();

    Livewire::test(EditCategory::class, ['record' => $main->id])
        ->set('data.parent_id', $other->id)   // the field is locked in the form; a forged value must not get through
        ->call('save');

    expect($main->fresh()->parent_id)->toBeNull();
});

test('dragging reorders the categories', function () {
    $ids = Category::orderBy('sort_order')->pluck('id')->all();
    $reversed = array_reverse($ids);

    Livewire::test(ListCategories::class)->call('reorderTable', $reversed);

    expect(Category::orderBy('sort_order')->pluck('id')->all())->toBe($reversed);
});

test('a category with articles cannot be deleted, with a reason', function () {
    $category = Category::where('slug', 'طاقة')->first();
    insertPost(['category_id' => $category->id]);

    Livewire::test(ListCategories::class)->callAction(TestAction::make('delete')->table($category))->assertNotified();

    expect(Category::whereKey($category->id)->exists())->toBeTrue();
});

test('a category with sub-categories cannot be deleted', function () {
    $main = Category::where('slug', 'فلسطين')->first();
    Category::create(['name_ar' => 'غزة', 'slug' => 'غزة', 'parent_id' => $main->id]);

    Livewire::test(ListCategories::class)->callAction(TestAction::make('delete')->table($main))->assertNotified();

    expect(Category::whereKey($main->id)->exists())->toBeTrue();
});

test('an unused category is deleted, and is removed from the menus with it', function () {
    $category = Category::create(['name_ar' => 'مؤقت', 'slug' => 'مؤقت']);
    DB::table('menu_items')->insert(['menu_id' => DB::table('menus')->where('key', 'header')->value('id'), 'label_ar' => 'مؤقت', 'category_id' => $category->id]);

    Livewire::test(ListCategories::class)->callAction(TestAction::make('delete')->table($category));

    expect(Category::whereKey($category->id)->exists())->toBeFalse()->and(DB::table('menu_items')->where('label_ar', 'مؤقت')->exists())->toBeFalse();
});

test('description, highlight and visibility are saved and every change is audited', function () {
    $category = Category::where('slug', 'طاقة')->first();

    Livewire::test(EditCategory::class, ['record' => $category->id])
        ->fillForm(['description' => 'أخبار الطاقة', 'is_highlighted' => true, 'is_active' => false])
        ->call('save')->assertHasNoFormErrors();

    expect($category->fresh())->description->toBe('أخبار الطاقة')->is_highlighted->toBeTrue()->is_active->toBeFalse();
    $audit = DB::table('audit_log')->where('subject_table', 'categories')->where('subject_id', $category->id)->where('action', 'update')->first();
    expect($audit)->not->toBeNull();
    expect(DB::table('audit_log_changes')->where('audit_id', $audit->id)->pluck('field')->all())->toContain('description', 'is_active');
});

// ---- tags ----------------------------------------------------------------------------------------

test('tag names typed into a form find the existing tag, even spelled differently', function () {
    $energy = Tag::create(['name_ar' => 'الطاقة', 'slug' => 'الطاقة']);

    $tags = app(TagService::class)->fromNames(['الطاقة', 'الطاقه', '#الطاقة', ' الطاقة ']);

    expect($tags)->toHaveCount(1)->and($tags->first()->id)->toBe($energy->id)->and(Tag::count())->toBe(1);
});

test('a new tag name creates a tag with a slug, in the order given, each once', function () {
    $tags = app(TagService::class)->fromNames(['بنوك', 'ذهب', 'بنوك', '', '   ', '#']);

    expect($tags->pluck('name_ar')->all())->toBe(['بنوك', 'ذهب'])->and($tags->pluck('slug')->all())->toBe(['بنوك', 'ذهب']);
});

test('tag names are cut to the column and extra spaces collapse', function () {
    $tag = app(TagService::class)->fromNames([str_repeat('ب', 200)])->first();
    $spaced = app(TagService::class)->fromNames(["سوق   الخضار\t  الجديد"])->first();

    expect(mb_strlen($tag->name_ar))->toBe(80)->and($spaced->name_ar)->toBe('سوق الخضار الجديد');
});

test('different names whose slugs would clash still both save', function () {
    $tags = app(TagService::class)->fromNames(['سوق؟', 'سوق!']);

    expect($tags->pluck('slug')->all())->toBe(['سوق', 'سوق-2']);
});

test('a tag saved by someone else a moment earlier is reused instead of failing', function () {
    // A second, committed connection plays the other editor: its tag appears between our lookup and our insert.
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    $other = DB::connection('pgsql_second');
    Tag::creating(function () use ($other) {
        static $done = false;
        if (! $done) {
            $done = true;
            $other->table('tags')->insert(['name_ar' => 'مفاجأة', 'slug' => 'مفاجأة']);
        }
    });

    try {
        $tags = app(TagService::class)->fromNames(['مفاجأة']);

        expect($tags)->toHaveCount(1)->and($tags->first()->slug)->toBe('مفاجأة')->and(Tag::count())->toBe(1);
    } finally {
        $other->table('tags')->where('slug', 'مفاجأة')->delete();   // it was committed outside the test's transaction
        $other->disconnect();
        Tag::flushEventListeners();
    }
});

test('the tag screen refuses a name that is the same word as another tag', function () {
    Tag::create(['name_ar' => 'الطاقة', 'slug' => 'الطاقة']);

    Livewire::test(CreateTag::class)->fillForm(['name_ar' => 'الطاقه'])->call('create')->assertHasFormErrors(['name_ar']);

    expect(Tag::count())->toBe(1);
});

test('tags can be created, renamed and deleted, and the list counts their articles', function () {
    Livewire::test(CreateTag::class)->fillForm(['name_ar' => 'عقارات'])->call('create')->assertHasNoFormErrors();
    $tag = Tag::where('name_ar', 'عقارات')->sole();
    $post = insertPost();
    DB::table('post_tags')->insert(['post_id' => $post, 'tag_id' => $tag->id]);

    Livewire::test(EditTag::class, ['record' => $tag->id])->fillForm(['name_ar' => 'العقارات'])->call('save')->assertHasNoFormErrors();
    expect($tag->fresh()->name_ar)->toBe('العقارات');

    $component = Livewire::test(ListTags::class);
    expect($component->instance()->getTableRecords()->first()->posts_count)->toBe(1);

    $component->callAction(TestAction::make('delete')->table($tag));
    expect(Tag::whereKey($tag->id)->exists())->toBeFalse()->and(DB::table('post_tags')->where('tag_id', $tag->id)->exists())->toBeFalse();
});

test('searching tags ignores hamza and ta marbuta like the rest of the site', function () {
    Tag::create(['name_ar' => 'إنترنت', 'slug' => 'إنترنت']);
    Tag::create(['name_ar' => 'ذهب', 'slug' => 'ذهب']);

    $found = Livewire::test(ListTags::class)->searchTable('انترنت')->instance()->getTableRecords()->pluck('name_ar')->all();

    expect($found)->toBe(['إنترنت']);
});

// ---- eras ----------------------------------------------------------------------------------------

test('eras can be added in order, edited and dragged', function () {
    $list = Livewire::test(ManageEras::class);
    $list->callAction(TestAction::make('create')->table(), ['name_ar' => 'العهد العثماني', 'year_from' => 1517, 'year_to' => 1917]);
    $list->callAction(TestAction::make('create')->table(), ['name_ar' => 'الانتداب', 'year_from' => 1920, 'year_to' => 1948]);

    $ids = Era::orderBy('sort_order')->pluck('id')->all();
    expect(Era::orderBy('sort_order')->pluck('name_ar')->all())->toBe(['العهد العثماني', 'الانتداب']);

    $list->call('reorderTable', array_reverse($ids));
    expect(Era::orderBy('sort_order')->pluck('name_ar')->all())->toBe(['الانتداب', 'العهد العثماني']);
});

test('an era cannot end before it begins, and names are unique', function () {
    Era::create(['name_ar' => 'العثماني', 'sort_order' => 1]);

    Livewire::test(ManageEras::class)
        ->callAction(TestAction::make('create')->table(), ['name_ar' => 'معكوسة', 'year_from' => 1950, 'year_to' => 1900])->assertHasFormErrors(['year_to']);
    Livewire::test(ManageEras::class)
        ->callAction(TestAction::make('create')->table(), ['name_ar' => 'العثماني'])->assertHasFormErrors(['name_ar']);

    expect(Era::count())->toBe(1);
});

// ---- sections ------------------------------------------------------------------------------------

test('the seven sections are listed and cannot be added or deleted', function () {
    $sections = Livewire::test(ListSections::class)->assertCanSeeTableRecords(Section::all());

    expect(Section::count())->toBe(7)->and(SectionResource::canCreate())->toBeFalse()
        ->and(SectionResource::canDelete(Section::first()))->toBeFalse()->and(SectionResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(SectionResource::getPages()))->toBe(['index', 'edit']);
});

test('a section changes its name, description and picture, hides, but keeps its key and address', function () {
    $section = Section::where('key', 'podcast')->first();
    $media = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'p.jpg', ['alt_text' => 'x'], null)->asset;
    Storage::fake('media_local_public');

    Livewire::test(EditSection::class, ['record' => $section->id])
        ->fillForm(['name_ar' => 'بودكاست رَقَم', 'description' => 'حلقات', 'cover_media_id' => $media->id, 'is_visible' => false])
        ->set('data.slug', 'hacked')->set('data.key', 'awareness')
        ->call('save')->assertHasNoFormErrors();

    expect($section->fresh())->name_ar->toBe('بودكاست رَقَم')->description->toBe('حلقات')->cover_media_id->toBe($media->id)
        ->is_visible->toBeFalse()->key->toBe('podcast')->slug->toBe('podcast');
});

test('only the descriptive fields of a section can be mass assigned', function () {
    $section = Section::where('key', 'podcast')->first();

    $section->update(['name_ar' => 'جديد', 'key' => 'awareness', 'slug' => 'hacked']);

    expect($section->fresh())->name_ar->toBe('جديد')->key->toBe('podcast')->slug->toBe('podcast');
});

test('sections can be reordered by dragging', function () {
    $ids = Section::orderBy('sort_order')->pluck('id')->all();

    Livewire::test(ListSections::class)->call('reorderTable', array_reverse($ids));

    expect(Section::orderBy('sort_order')->pluck('id')->all())->toBe(array_reverse($ids));
});

test('a section cover from the media library is protected from the trash', function () {
    Storage::fake('media_local_public');
    $media = app(MediaUploader::class)->store(uploadFile(imageBytes(310, 200)), 'p.jpg', ['alt_text' => 'x'], null)->asset;
    Section::where('key', 'podcast')->update(['cover_media_id' => $media->id]);

    expect(fn () => app(MediaTrash::class)->trash($media))->toThrow(MediaInUse::class);
});

test('all these screens are for signed-in staff only', function (string $path) {
    auth()->logout();
    $this->get('/'.config('raqam.admin_path').$path)->assertRedirect();
    $this->actingAs(User::factory()->create())->get('/'.config('raqam.admin_path').$path)->assertForbidden();
})->with(['/categories', '/tags', '/sections', '/eras', '/authors', '/guests', '/menus']);
