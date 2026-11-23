<?php

use App\Content\PostSaver;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Jobs\GenerateMediaVariants;
use App\Media\MediaUploader;
use App\Models\Author;
use App\Models\Category;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\PalestineTime;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class]);
    ensurePostKinds();
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->energy = Category::where('slug', 'طاقة')->value('id');
});

function newPost(string $kind = 'news', string $title = 'مسودة جديدة'): Post
{
    return app(PostSaver::class)->create($kind, $title, auth()->id());
}

/** A button of the bar under the editor's form (the form's actions, not the page's header). */
function barAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', schema: 'content');
}

/** @return array<string, string> the bar's buttons, name => label, in order (the state line left out). */
function barButtons(Post $post): array
{
    $page = edit($post)->instance();
    $out = [];
    foreach ((fn () => $this->getFormActions())->call($page) as $action) {
        // The rarer changes of state wait in «المزيد»: listed in their place, after the buttons before it.
        foreach ($action instanceof ActionGroup ? $action->getActions() : [$action] as $one) {
            if ($one->getName() !== 'saveState') {
                $out[$one->getName()] = (string) $one->getLabel();
            }
        }
    }

    return $out;
}

function edit(Post $post)
{
    return Livewire::test(EditPost::class, ['record' => $post->id]);
}

test('the editor and the list are for signed-in staff only', function () {
    $post = newPost();
    auth()->logout();

    foreach (['/posts', '/posts/create', "/posts/{$post->id}/edit"] as $path) {
        $this->get('/'.config('raqam.admin_path').$path)->assertRedirect();
    }
    foreach (['/posts', "/posts/{$post->id}/edit"] as $path) {
        $this->actingAs(User::factory()->create())->get('/'.config('raqam.admin_path').$path)->assertForbidden();
    }
});

test('a new item is made from a kind and a title, and the editor opens on the saved draft', function () {
    $component = Livewire::test(CreatePost::class)->fillForm(['kind_code' => 'episode', 'title' => 'الحلقة الأولى'])->call('create')->assertHasNoFormErrors();

    $post = Post::sole();
    expect($post)->kind_code->toBe('episode')->title->toBe('الحلقة الأولى')->status->toBe('draft')->created_by->toBe($this->admin->id);
    $component->assertRedirect('/'.config('raqam.admin_path')."/posts/{$post->id}/edit");
});

test('a title and a real kind are needed to start', function () {
    Livewire::test(CreatePost::class)->fillForm(['kind_code' => 'news', 'title' => ''])->call('create')->assertHasFormErrors(['title']);
    Livewire::test(CreatePost::class)->fillForm(['title' => 'عنوان'])->set('data.kind_code', 'podcastx')->call('create')->assertHasFormErrors(['kind_code']);

    expect(Post::count())->toBe(0);
});

test('the editor shows the item and saves its text, filing and extras', function () {
    $author = Author::create(['name' => 'سامي', 'slug' => 'sami']);
    $post = newPost();

    edit($post)
        ->assertFormSet(['title' => 'مسودة جديدة', 'status' => 'draft'])
        ->fillForm([
            'title' => 'عنوان جديد للخبر', 'subtitle' => 'فرعي', 'excerpt' => 'ملخص الخبر', 'body' => '<p>نص الخبر</p>',
            'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'authors' => [(string) $author->id],
            'tags' => ['ذهب', 'أسواق'], 'is_featured' => true,
        ])
        ->call('save')->assertHasNoFormErrors();

    $post->refresh();
    expect($post)->title->toBe('عنوان جديد للخبر')->subtitle->toBe('فرعي')->excerpt->toBe('ملخص الخبر')->category_id->toBe($this->energy)->is_featured->toBeTrue();
    expect($post->authors->pluck('id')->all())->toBe([$author->id])->and($post->tags->pluck('name_ar')->sort()->values()->all())->toBe(['أسواق', 'ذهب']);
});

test('what was saved comes back into the form: authors, tags, gallery, guests, kind facts and SEO', function () {
    $author = Author::create(['name' => 'سامي', 'slug' => 'sami']);
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'g']);
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 3]);
    $post = newPost('episode', 'حلقة');

    edit($post)->fillForm([
        'authors' => [(string) $author->id], 'tags' => ['اقتصاد'], 'gallery' => [['media_id' => (string) $this->cover->id]],
        'guests' => [['guest_id' => (string) $guest->id, 'role_code' => 'host']],
        'episode' => ['season_id' => $season, 'episode_number' => 4], 'seo' => ['meta_title' => 'عنوان بحث'],
    ])->call('save')->assertHasNoFormErrors();

    $loaded = edit($post->fresh());
    $loaded->assertSet('data.authors', [$author->id])->assertSet('data.tags', ['اقتصاد'])->assertSet('data.episode.episode_number', 4)->assertSet('data.seo.meta_title', 'عنوان بحث');

    // Repeater rows are keyed by random ids, so read them by position.
    expect(array_values($loaded->get('data.gallery'))[0]['media_id'])->toBe((string) $this->cover->id)
        ->and(array_values($loaded->get('data.guests'))[0]['role_code'])->toBe('host');
});

test('publishing needs the cover, the text and the category, with the message under the field', function () {
    $post = newPost();

    edit($post)->fillForm(['body' => '<p>نص</p>', 'category_id' => $this->energy])
        ->callAction(barAction('publishNow'))->assertHasErrors(['data.cover_media_id']);
    edit($post)->fillForm(['cover_media_id' => $this->cover->id, 'category_id' => $this->energy, 'body' => ''])
        ->callAction(barAction('publishNow'))->assertHasErrors(['data.body']);

    expect($post->fresh()->status)->toBe('draft');
});

test('publishing works, and the quick button publishes with the current text', function () {
    $post = newPost();

    edit($post)->fillForm(['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id])
        ->callAction(barAction('publishNow'))->assertHasNoFormErrors();

    expect($post->fresh())->status->toBe('published')->and($post->fresh()->published_at)->not->toBeNull();
});

test('the quick publish button on an incomplete item explains what is missing and closes its dialog', function () {
    $post = newPost();

    $page = edit($post)->callAction(barAction('publishNow'))   // no text, no category, no cover
        ->assertNotified()
        ->assertHasErrors(['data.cover_media_id'])
        ->assertSet('mountedActions', [])             // the confirmation dialog is gone, not stuck open
        ->assertSet('data.status', 'draft');           // and the form does not claim the item is published

    expect($post->fresh()->status)->toBe('draft');
});

test('the quick publish button is not offered for an item that is already published', function () {
    $post = newPost();
    app(PostSaver::class)->save($post, ['title' => 'x', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);

    edit($post->fresh())->assertActionDoesNotExist(barAction('publishNow'));
});

test('the time typed to schedule is Palestine time, as the label says, is stored as UTC and shown back as typed', function () {
    $post = newPost();

    // 14 January 12:00 in Palestine (UTC+2 in winter) is 10:00 UTC.
    edit($post)->fillForm(['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id])
        ->callAction(barAction('schedule'), ['at' => '2030-01-14 12:00'])->assertHasNoFormErrors()
        ->assertSet('data.published_at', '2030-01-14 12:00:00');   // the same page, after the save

    expect($post->fresh()->published_at->utc()->format('Y-m-d H:i'))->toBe('2030-01-14 10:00');
    edit($post->fresh())->assertSet('data.published_at', '2030-01-14 12:00:00');   // and opened again
});

test('breaking news from the quick action sets an end time and saves', function () {
    $post = newPost();
    app(PostSaver::class)->save($post, ['title' => 'x', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);

    edit($post->fresh())->callAction('breaking', ['hours' => 6])->assertHasNoFormErrors();

    expect($post->fresh()->isBreaking())->toBeTrue()->and($post->fresh()->breaking_until->diffInHours(now(), true))->toBeBetween(5, 6);
});

test('the sponsor and the featured flag are saved from the form', function () {
    $org = Organization::create(['name' => 'راعٍ', 'slug' => 'sp']);
    $post = newPost();

    edit($post)->fillForm(['sponsor_organization_id' => $org->id, 'is_featured' => true])->call('save')->assertHasNoFormErrors();

    expect($post->fresh())->sponsor_organization_id->toBe($org->id)->is_featured->toBeTrue();
});

test('the text is cleaned on save and the form then shows the cleaned text', function () {
    $post = newPost();

    edit($post)->fillForm(['body' => '<p onclick="x()">نص<script>alert(1)</script></p>'])->call('save')->assertHasNoFormErrors();

    expect($post->fresh()->body)->toBe('<p>نص</p>');
});

test('two tabs: the second save is refused with a clear message and the first stays', function () {
    $post = newPost();
    $first = edit($post);
    $second = edit($post);

    $this->travel(2)->seconds();
    $first->fillForm(['title' => 'من التبويب الأول'])->call('save')->assertHasNoFormErrors();
    $second->fillForm(['title' => 'من التبويب الثاني'])->call('save')->assertNotified();

    expect($post->fresh()->title)->toBe('من التبويب الأول');
});

test('the address can be changed by hand, and a published item then redirects the old one', function () {
    $post = newPost();
    app(PostSaver::class)->save($post, ['title' => 'عنوان', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);
    $old = $post->fresh()->slug;

    edit($post->fresh())->fillForm(['slug' => 'رابط-جديد'])->call('save')->assertHasNoFormErrors();

    expect(DB::table('redirects')->where('from_path', "/news/{$post->id}/{$old}")->value('to_path'))->toBe("/news/{$post->id}/رابط-جديد");
});

test('the SEO fields are saved with the item', function () {
    $post = newPost();

    edit($post)->fillForm(['seo' => ['meta_title' => 'عنوان لمحركات البحث', 'meta_description' => 'وصف قصير', 'robots_noindex' => true]])->call('save')->assertHasNoFormErrors();

    expect($post->fresh()->seoMeta)->meta_title->toBe('عنوان لمحركات البحث')->robots_noindex->toBeTrue();
});

test('the SEO fields show what will be used when they are left empty', function () {
    $post = newPost('news', 'عنوان المادة الظاهر');
    app(PostSaver::class)->save($post, ['title' => 'عنوان المادة الظاهر', 'excerpt' => 'ملخص المادة الظاهر'], $this->admin->id);

    edit($post->fresh())->assertSeeHtml('placeholder="عنوان المادة الظاهر"')->assertSeeHtml('placeholder="ملخص المادة الظاهر"');
});

test('the SEO assistant answers with advice that follows the form', function () {
    $post = newPost();

    edit($post)->assertSee('العنوان قصير')->assertSee('اختر صورة رئيسية')->assertSee('لا يوجد رابط داخلي');
});

test('autosave stores the text of a draft, not the rest of the form', function () {
    $post = newPost();

    edit($post)->fillForm(['title' => 'عنوان في الطريق', 'body' => '<p>نص يُكتب الآن</p>', 'is_featured' => true, 'status' => 'published'])
        ->call('autosave')->assertDispatched('post-autosaved');

    expect($post->fresh())->title->toBe('عنوان في الطريق')->body->toBe('<p>نص يُكتب الآن</p>')->is_featured->toBeFalse()->status->toBe('draft');
    expect(DB::table('post_revisions')->where('post_id', $post->id)->count())->toBe(1);
});

test('autosave does nothing for a published item', function () {
    $post = newPost();
    app(PostSaver::class)->save($post, ['title' => 'x', 'body' => '<p>الأصلي</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);

    edit($post->fresh())->fillForm(['body' => '<p>تغيير بلا حفظ</p>'])->call('autosave');

    expect($post->fresh()->body)->toBe('<p>الأصلي</p>');
});

test('a second autosave goes through, and an explicit save afterwards is not told the item changed elsewhere', function () {
    $post = newPost();
    $component = edit($post);

    $this->travel(30)->seconds();
    $component->fillForm(['body' => '<p>أ</p>'])->call('autosave');
    $this->travel(30)->seconds();
    $component->fillForm(['body' => '<p>ب</p>'])->call('autosave')->assertNotDispatched('post-autosave-failed');
    $component->fillForm(['title' => 'عنوان نهائي'])->call('save')->assertHasNoFormErrors();

    expect($post->fresh())->title->toBe('عنوان نهائي')->body->toBe('<p>ب</p>');
});

test('autosave reports a change made elsewhere instead of overwriting it', function () {
    $post = newPost();
    $component = edit($post);
    $this->travel(2)->seconds();
    app(PostSaver::class)->save($post->fresh(), ['title' => 'من مكان آخر', 'body' => '<p>م</p>'], $this->admin->id);

    $component->fillForm(['body' => '<p>نصي</p>'])->call('autosave')->assertDispatched('post-autosave-failed');

    expect($post->fresh()->title)->toBe('من مكان آخر');
});

test('the preview action gives a working secret link', function () {
    $post = newPost();

    edit($post)->callAction('preview', ['hours' => 24])->assertNotified();
});

test('an earlier revision can be put back from the editor', function () {
    $post = newPost('news', 'الأول');
    app(PostSaver::class)->save($post, ['title' => 'الأول', 'body' => '<p>نص أول</p>'], $this->admin->id);
    app(PostSaver::class)->save($post->fresh(), ['title' => 'الثاني', 'body' => '<p>نص ثان</p>'], $this->admin->id);
    $first = DB::table('post_revisions')->where('post_id', $post->id)->where('title', 'الأول')->orderByDesc('revision_no')->value('revision_no');

    edit($post->fresh())->callAction('revisions', ['revision_no' => $first])->assertNotified();

    expect($post->fresh())->title->toBe('الأول')->body->toBe('<p>نص أول</p>');
});

test('putting back a revision the rules refuse says why and closes the dialog', function () {
    $post = newPost();   // revision 1 is the empty draft it was created as
    app(PostSaver::class)->save($post, ['title' => 'x', 'body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);

    edit($post->fresh())->callAction('revisions', ['revision_no' => 1])   // an empty text on a published item
        ->assertNotified()
        ->assertSet('mountedActions', []);

    expect($post->fresh()->body)->toBe('<p>نص</p>');
});

test('moving to the trash from the editor, and getting it back from the list', function () {
    $post = newPost();

    edit($post)->callAction('trash');
    expect(Post::find($post->id))->toBeNull()->and(Post::withTrashed()->find($post->id))->not->toBeNull();

    // The trash tab really lists it (an editor has to see it to restore it), and the other tabs do not.
    Livewire::test(ListPosts::class)->set('activeTab', 'trash')->assertCanSeeTableRecords([Post::withTrashed()->find($post->id)])
        ->callAction(TestAction::make('restore')->table(Post::withTrashed()->find($post->id)));
    expect(Post::find($post->id))->not->toBeNull();
});

test('a trashed item is only in the trash tab', function () {
    $post = newPost();
    edit($post)->callAction('trash');

    Livewire::test(ListPosts::class)->assertCanNotSeeTableRecords([Post::withTrashed()->find($post->id)]);
});

test('the list shows items by status, searches ignoring hamza, and filters by kind', function () {
    $news = newPost('news', 'أسعار الذهب');
    $story = newPost('story', 'قصة نجاح');
    app(PostSaver::class)->save($news, ['title' => 'أسعار الذهب', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);

    Livewire::test(ListPosts::class)->assertCanSeeTableRecords([$news, $story])
        ->set('activeTab', 'published')->assertCanSeeTableRecords([$news->fresh()])->assertCanNotSeeTableRecords([$story])
        ->set('activeTab', 'all')->searchTable('اسعار')->assertCanSeeTableRecords([$news])->assertCanNotSeeTableRecords([$story])
        ->searchTable('')->filterTable('kind_code', 'story')->assertCanSeeTableRecords([$story])->assertCanNotSeeTableRecords([$news]);
});

test('bulk actions move many items through the same rules and report the ones that cannot', function () {
    $ready = newPost();
    app(PostSaver::class)->save($ready, ['title' => 'جاهز', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);
    $draft = newPost('news', 'مسودة لم تُنشر');

    Livewire::test(ListPosts::class)->selectTableRecords([$ready->id, $draft->id])
        ->callAction(TestAction::make('archive')->table()->bulk())->assertNotified();

    expect($ready->fresh()->status)->toBe('archived')->and($draft->fresh()->status)->toBe('draft');   // a draft that was never published cannot be archived

    Livewire::test(ListPosts::class)->selectTableRecords([$ready->id])->callAction(TestAction::make('unpublish')->table()->bulk());
    expect($ready->fresh()->status)->toBe('draft');
});

test('bulk trash moves the selected items to the trash', function () {
    $a = newPost('news', 'أ');
    $b = newPost('news', 'ب');
    $keep = newPost('news', 'ج');

    Livewire::test(ListPosts::class)->selectTableRecords([$a->id, $b->id])->callAction(TestAction::make('trash')->table()->bulk());

    expect(Post::count())->toBe(1)->and(Post::find($keep->id))->not->toBeNull()->and(Post::onlyTrashed()->count())->toBe(2);
});

// ---- the bar under the form: publishing by state (panel phase 3) --------------------------------

test('a draft\'s bar publishes, schedules or keeps it a draft, and Ctrl+S only saves', function () {
    $post = newPost();

    expect(barButtons($post))->toBe(['publishNow' => 'نشر', 'schedule' => 'جدولة…', 'save' => 'حفظ كمسودة', 'cancel' => 'إلغاء']);
    expect((fn () => $this->getFormActions())->call(edit($post)->instance())[3]->getKeyBindings())->toBe(['mod+s']);
    edit($post)->assertActionDoesNotExist('publishNow');   // no longer in the header
});

test('a published item is updated, unpublished or archived; a scheduled one updated, published at once, moved or unscheduled; an archived one updated or published again', function () {
    $published = newPost('news', 'منشور');
    app(PostSaver::class)->save($published, ['title' => 'منشور', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);
    expect(barButtons($published->fresh()))->toBe(['save' => 'تحديث', 'unpublish' => 'إلغاء النشر', 'archive' => 'أرشفة', 'cancel' => 'إلغاء']);

    $scheduled = newPost('news', 'مجدول');
    app(PostSaver::class)->save($scheduled, ['title' => 'مجدول', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'scheduled', 'published_at' => now()->addDay()->format('Y-m-d H:i:s')], $this->admin->id);
    expect(barButtons($scheduled->fresh()))->toBe(['save' => 'تحديث', 'publishNow' => 'نشر الآن', 'schedule' => 'تغيير الموعد…', 'unschedule' => 'إلغاء الجدولة', 'cancel' => 'إلغاء']);

    app(PostSaver::class)->save($published->fresh(), ['title' => 'منشور', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'archived'], $this->admin->id);
    expect(barButtons($published->fresh()))->toBe(['save' => 'تحديث', 'publishNow' => 'نشر', 'cancel' => 'إلغاء']);
});

test('the state is read only on the page: it changes through the bar alone', function () {
    edit(newPost())->assertFormFieldIsDisabled('status');

    $scheduled = newPost('news', 'مجدول');
    app(PostSaver::class)->save($scheduled, ['title' => 'مجدول', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'scheduled', 'published_at' => now()->addDay()->format('Y-m-d H:i:s')], $this->admin->id);
    edit($scheduled->fresh())->assertFormFieldIsDisabled('status')->assertFormFieldIsDisabled('published_at');
});

test('a save that still carries another state is refused by the server, whatever the page sent', function () {
    $draft = newPost('news', 'مسودة مكتملة');
    $complete = ['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id];

    // The read-only select forced in the request, then Ctrl+S: refused, nothing published.
    edit($draft)->fillForm($complete)->set('data.status', 'published')->call('save')->assertHasErrors(['data.status']);
    expect($draft->fresh()->status)->toBe('draft');
    // The same through another button that saves (عاجل): still refused.
    edit($draft)->fillForm($complete)->set('data.status', 'published')->callAction('breaking', ['hours' => 1]);
    expect($draft->fresh()->status)->toBe('draft');

    $published = newPost('news', 'منشور');
    app(PostSaver::class)->save($published, ['title' => 'منشور', ...$complete, 'status' => 'published'], $this->admin->id);
    edit($published->fresh())->set('data.status', 'draft')->call('save')->assertHasErrors(['data.status']);
    expect($published->fresh()->status)->toBe('published');

    // A scheduled item's time forced in the request does not move it.
    $scheduled = newPost('news', 'مجدول');
    $at = now()->addDay()->startOfMinute();
    app(PostSaver::class)->save($scheduled, ['title' => 'مجدول', ...$complete, 'status' => 'scheduled', 'published_at' => $at->format('Y-m-d H:i:s')], $this->admin->id);
    edit($scheduled->fresh())->set('data.published_at', now('Asia/Hebron')->addMinutes(5)->format('Y-m-d H:i:s'))->call('save')->assertHasNoFormErrors();
    expect($scheduled->fresh()->published_at->equalTo($at))->toBeTrue();

    // A plain save of the same state goes through.
    edit($published->fresh())->fillForm(['title' => 'منشور معدّل'])->call('save')->assertHasNoFormErrors();
    expect($published->fresh())->title->toBe('منشور معدّل')->status->toBe('published');
});

test('a published item is unpublished or archived, a scheduled one unscheduled, each confirming with its title', function () {
    $complete = ['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id];
    $post = newPost('news', 'خبر الميناء');
    app(PostSaver::class)->save($post, ['title' => 'خبر الميناء', ...$complete, 'status' => 'published'], $this->admin->id);

    edit($post->fresh())->mountAction(barAction('unpublish'))->assertMountedActionModalSee('إلغاء النشر «خبر الميناء»');
    edit($post->fresh())->callAction(barAction('unpublish'))->assertHasNoFormErrors()->assertSeeHtml('wire:key="post-bar-draft"');
    expect($post->fresh()->status)->toBe('draft');

    app(PostSaver::class)->save($post->fresh(), ['title' => 'خبر الميناء', ...$complete, 'status' => 'published'], $this->admin->id);
    edit($post->fresh())->mountAction(barAction('archive'))->assertMountedActionModalSee('أرشفة «خبر الميناء»');
    edit($post->fresh())->callAction(barAction('archive'))->assertHasNoFormErrors();
    expect($post->fresh()->status)->toBe('archived');

    $scheduled = newPost('news', 'خبر الغد');
    app(PostSaver::class)->save($scheduled, ['title' => 'خبر الغد', ...$complete, 'status' => 'scheduled', 'published_at' => now()->addDay()->format('Y-m-d H:i:s')], $this->admin->id);
    edit($scheduled->fresh())->mountAction(barAction('unschedule'))->assertMountedActionModalSee('إلغاء الجدولة «خبر الغد»');
    edit($scheduled->fresh())->callAction(barAction('unschedule'))->assertHasNoFormErrors();
    expect($scheduled->fresh()->status)->toBe('draft');

    // «تغيير الموعد…» moves it, and it stays scheduled.
    app(PostSaver::class)->save($scheduled->fresh(), ['title' => 'خبر الغد', ...$complete, 'status' => 'scheduled', 'published_at' => now()->addDay()->format('Y-m-d H:i:s')], $this->admin->id);
    $later = now('Asia/Hebron')->addDays(3)->setTime(9, 30);
    edit($scheduled->fresh())->callAction(barAction('schedule'), ['at' => $later->format('Y-m-d H:i')])->assertHasNoFormErrors();
    expect($scheduled->fresh())->status->toBe('scheduled')->and($scheduled->fresh()->published_at->equalTo($later))->toBeTrue();
});

/** The next time Palestine moves its clocks in the given month (3 = forward, 10 = back), from the time-zone database. */
function nextPalestineClockChange(int $month): CarbonImmutable
{
    $zone = new DateTimeZone('Asia/Hebron');
    foreach ($zone->getTransitions(now()->getTimestamp(), now()->addYears(2)->getTimestamp()) as $i => $t) {
        $at = CarbonImmutable::createFromTimestampUTC($t['ts']);
        if ($i > 0 && (int) $at->format('n') === $month) {
            return $at;
        }
    }
    throw new RuntimeException('no clock change found');
}

test('scheduling across the night the clocks go forward (March): the time is the one typed, and a skipped time is refused', function () {
    $change = nextPalestineClockChange(3);   // e.g. 00:00 UTC, when 02:00 becomes 03:00
    $before = $change->subHours(1)->setTimezone('Asia/Hebron');   // still winter time
    $after = $change->addMinutes(90)->setTimezone('Asia/Hebron');   // summer time
    $complete = ['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id];

    foreach ([$before, $after] as $typed) {
        $post = newPost('news', 'مجدول '.$typed->format('H:i'));
        $page = edit($post)->fillForm($complete)->callAction(barAction('schedule'), ['at' => $typed->format('Y-m-d H:i')])->assertHasNoFormErrors()
            ->assertSet('data.published_at', $typed->format('Y-m-d H:i:00'));
        expect($post->fresh()->published_at->utc()->format('Y-m-d H:i'))->toBe($typed->utc()->format('Y-m-d H:i'));
        edit($post->fresh())->assertSet('data.published_at', $typed->format('Y-m-d H:i:00'));
    }

    $post = newPost('news', 'وقت غير موجود');
    // The clock jumps from the hour before the summer hour to it: half past the hour in between is never read.
    $summer = $change->setTimezone('Asia/Hebron');
    $wallClock = $summer->format('Y-m-d').' '.sprintf('%02d:30', (int) $summer->format('H') - 1);
    expect(PalestineTime::fromTyped($wallClock))->toBeNull();
    edit($post)->fillForm($complete)->callAction(barAction('schedule'), ['at' => $wallClock])->assertHasActionErrors(['at']);
    expect($post->fresh()->status)->toBe('draft');
});

test('scheduling across the night the clocks go back (October): the time shown after saving is the time typed', function () {
    $change = nextPalestineClockChange(10);   // e.g. 23:00 UTC, when 02:00 summer becomes 01:00 winter
    $complete = ['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id];

    foreach (['before' => $change->subHours(2), 'twice' => $change->addMinutes(30), 'after' => $change->addHours(2)] as $name => $moment) {
        $typed = $moment->setTimezone('Asia/Hebron')->format('Y-m-d H:i');
        $post = newPost('news', 'مجدول '.$name);
        edit($post)->fillForm($complete)->callAction(barAction('schedule'), ['at' => $typed])->assertHasNoFormErrors()
            ->assertSet('data.published_at', $typed.':00');
        edit($post->fresh())->assertSet('data.published_at', $typed.':00');
        // In the hour lived twice the second one is meant, on winter time (PalestineTime).
        expect($post->fresh()->published_at->utc()->format('Y-m-d H:i'))->toBe($moment->utc()->format('Y-m-d H:i'), $name);
    }
});

test('«جدولة…» asks for the time and schedules the draft with the current text; a time in the past is refused', function () {
    $post = newPost();
    $page = edit($post)->fillForm(['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id]);

    $page->callAction(barAction('schedule'), ['at' => now('Asia/Hebron')->subHour()->format('Y-m-d H:i')])->assertNotified();
    expect($post->fresh()->status)->toBe('draft');

    $at = now('Asia/Hebron')->addDay()->startOfMinute();
    $page->callAction(barAction('schedule'), ['at' => $at->format('Y-m-d H:i')])->assertHasNoFormErrors();
    expect($post->fresh())->status->toBe('scheduled')
        ->and($post->fresh()->published_at->equalTo($at))->toBeTrue()
        ->and($post->fresh()->body)->toContain('نص');
});

test('the confirmation to publish names the item', function () {
    $post = newPost('news', 'خبر عن السوق');

    edit($post)->mountAction(barAction('publishNow'))->assertMountedActionModalSee('نشر «خبر عن السوق»');
});

test('the bar stays on screen and starts with the item\'s state and whether changes are saved', function () {
    $post = newPost();

    expect(edit($post)->instance()->areFormActionsSticky())->toBeTrue();
    edit($post)->assertSeeHtml('raqam-save-state')->assertSee('مسودة')->assertSee('لا تغييرات غير محفوظة', false);
});

test('after publishing from the bar, the same page\'s bar is the published item\'s, keyed by the state', function () {
    $post = newPost();

    edit($post)->assertSeeHtml('wire:key="post-bar-draft"')
        ->fillForm(['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id])
        ->callAction(barAction('publishNow'))->assertHasNoFormErrors()->assertNoRedirect()
        ->assertSeeHtml('wire:key="post-bar-published"')->assertDontSeeHtml('wire:key="post-bar-draft"')
        ->assertActionDoesNotExist(barAction('publishNow'))->assertActionHasLabel(barAction('save'), 'تحديث');
});
