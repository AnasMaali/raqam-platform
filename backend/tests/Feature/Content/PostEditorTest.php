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

test('publishing from the form needs the cover, the text and the category, with the message under the field', function () {
    $post = newPost();

    edit($post)->fillForm(['status' => 'published', 'body' => '<p>نص</p>', 'category_id' => $this->energy])
        ->call('save')->assertHasErrors(['data.cover_media_id']);
    edit($post)->fillForm(['status' => 'published', 'cover_media_id' => $this->cover->id, 'category_id' => $this->energy, 'body' => ''])
        ->call('save')->assertHasErrors(['data.body']);

    expect($post->fresh()->status)->toBe('draft');
});

test('publishing works, and the quick button publishes with the current text', function () {
    $post = newPost();

    edit($post)->fillForm(['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id])
        ->callAction('publishNow')->assertHasNoFormErrors();

    expect($post->fresh())->status->toBe('published')->and($post->fresh()->published_at)->not->toBeNull();
});

test('the quick publish button is not offered for an item that is already published', function () {
    $post = newPost();
    app(PostSaver::class)->save($post, ['title' => 'x', 'body' => '<p>n</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'published'], $this->admin->id);

    edit($post->fresh())->assertActionHidden('publishNow');
});

test('scheduling from the form needs a future time and publishes through the queue', function () {
    $post = newPost();
    $data = ['body' => '<p>نص</p>', 'category_id' => $this->energy, 'cover_media_id' => $this->cover->id, 'status' => 'scheduled'];

    edit($post)->fillForm($data + ['published_at' => now()->subDay()->format('Y-m-d H:i:s')])->call('save')->assertHasErrors(['data.published_at']);

    edit($post)->fillForm($data + ['published_at' => now()->addDay()->format('Y-m-d H:i:s')])->call('save')->assertHasNoFormErrors();
    expect($post->fresh()->status)->toBe('scheduled');
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

test('moving to the trash from the editor, and getting it back from the list', function () {
    $post = newPost();

    edit($post)->callAction('trash');
    expect(Post::find($post->id))->toBeNull()->and(Post::withTrashed()->find($post->id))->not->toBeNull();

    Livewire::test(ListPosts::class)->set('activeTab', 'trash')->callAction(TestAction::make('restore')->table(Post::withTrashed()->find($post->id)));
    expect(Post::find($post->id))->not->toBeNull();
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
