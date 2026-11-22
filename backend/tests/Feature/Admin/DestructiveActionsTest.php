<?php

use App\Content\PostSaver;
use App\Filament\Actions\Destructive;
use App\Filament\Pages\HomeLayoutEditor;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Destructive actions are quiet until asked (panel phase 3): a grey button, red only inside the confirmation, which names
 * what goes. The bins of a list's rows (the home page's blocks above all) are grey too, and say what they do.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
});

test('no screen draws a red button of its own: red is left to the confirmation', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || $file->getPathname() === app_path('Filament/Actions/Destructive.php')) {
            continue;
        }
        if (preg_match("~->color\\(\\s*'danger'\\s*\\)~", file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

test('moving an item to the trash: a grey button, a confirmation that names it, the red button inside', function () {
    $post = app(PostSaver::class)->create('news', 'خبر سيُنقل', auth()->id());
    $page = Livewire::test(EditPost::class, ['record' => $post->id]);
    $trash = $page->instance()->getAction('trash');

    expect($trash->getColor())->toBe('gray');
    $page->mountAction('trash')->assertMountedActionModalSee('نقل إلى السلة «خبر سيُنقل»');
    expect($page->instance()->getMountedAction()->getModalSubmitAction()->getColor())->toBe('danger');

    // The other buttons of the header are quiet too: the bar under the form holds the one primary button.
    foreach (['breaking', 'preview', 'revisions'] as $name) {
        expect($page->instance()->getAction($name)->getColor())->toBe('gray', $name);
    }
});

test('a delete in a list names the row, and a delete of several says how many', function () {
    $tag = Tag::query()->create(['name_ar' => 'اقتصاد محلي', 'slug' => 'اقتصاد-محلي']);

    Livewire::test(ListTags::class)->mountAction(TestAction::make('delete')->table($tag))
        ->assertMountedActionModalSee('حذف «اقتصاد محلي»');

    $posts = collect([1, 2, 3])->map(fn ($i) => app(PostSaver::class)->create('news', "مادة {$i}", auth()->id()));
    Livewire::test(ListPosts::class)->selectTableRecords($posts->pluck('id')->all())
        ->mountAction(TestAction::make('trash')->table()->bulk())->assertMountedActionModalSee('نقل إلى السلة (3)');
});

test('a record is named by the first column that names it on screen', function () {
    expect(Destructive::name(new Tag(['name_ar' => 'وسم'])))->toBe('وسم')
        ->and(Destructive::name(new Post(['title' => 'عنوان'])))->toBe('عنوان')
        ->and(Destructive::name(null))->toBe('')
        ->and(Destructive::heading('حذف', new Post(['title' => str_repeat('ا', 100)])))->toEndWith('…»');
});

test('the home page\'s blocks are removed by a quiet bin that says what it does', function () {
    $html = Livewire::test(HomeLayoutEditor::class)->html();

    // The tooltip is written into the page as a JavaScript string (\u escapes).
    expect($html)->toContain(trim(json_encode('إزالة هذا الجزء من الرئيسية'), '"'))
        ->not->toMatch('~fi-color-danger~');
});

test('every other list\'s bin is quiet too, and says «إزالة»', function () {
    // Any repeater made anywhere in the panel (a gallery, a menu, a video's chapters) gets the quiet bin.
    $bin = Repeater::make('items')->getDeleteAction();

    expect($bin->getColor())->toBe('gray')->and($bin->getTooltip())->toBe('إزالة');
});
