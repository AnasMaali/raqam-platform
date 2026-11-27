<?php

use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * A list's cover picture links to the same page as the title beside it: that second link is kept from the keyboard and
 * from screen readers, so the title is read once and no link is left without a name (axe "link-name"). A picture that
 * is the only link is named by the title.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    ensurePostKinds();
    $this->actingAs(User::factory()->staff()->create());
});

/** The cover view with a record, a picture and the address the table gives the row. */
function coverHtml(?string $url, bool $titleLinks, string $title = 'خبر الغلاف', ?string $picture = '/media/thumb.jpg'): string
{
    $table = new class($url)
    {
        public function __construct(private ?string $url) {}

        public function getRecordUrl(mixed $record): ?string
        {
            return $this->url;
        }
    };
    $column = new class($table)
    {
        public function __construct(private object $table) {}

        public function getTable(): object
        {
            return $this->table;
        }
    };

    return view('filament.columns.cover-link', ['getRecord' => fn () => new Post, 'getState' => fn () => $picture, 'column' => $column,
        'titleLinks' => $titleLinks, 'title' => $title])->render();
}

test('beside a title that links to the same page, the picture is a link for the pointer only: no tab stop, hidden, empty alt', function () {
    $html = coverHtml('https://panel.example/posts/7/edit', titleLinks: true);

    expect($html)->toContain('<a href="https://panel.example/posts/7/edit" class="raqam-cover-link"  tabindex="-1" aria-hidden="true" >')
        ->toContain('alt=""')->not->toContain('خبر الغلاف');
});

test('a picture that is the only link is named by the title and stays in the tab order', function () {
    $html = coverHtml('https://panel.example/posts/7/edit', titleLinks: false, title: 'خبر <الغلاف>');

    expect($html)->toContain('alt="خبر &lt;الغلاف&gt;"')->not->toContain('tabindex')->not->toContain('aria-hidden');
});

test('with no address the picture is no link, and with no picture nothing is drawn', function () {
    expect(coverHtml(null, titleLinks: true))->not->toContain('<a ')->toContain('alt=""')
        ->and(trim(coverHtml('https://panel.example/posts/7/edit', titleLinks: true, picture: null)))->toBe('');
});

test('on the posts list the picture is its own hidden link, not wrapped in the row\'s link', function () {
    $picture = insertMedia(['original_name' => 'cover.jpg']);
    $id = insertPost(['title' => 'خبر بصورة', 'cover_media_id' => $picture]);

    $html = Livewire::test(ListPosts::class)->html();
    $edit = e(route('filament.admin.resources.posts.edit', ['record' => $id]));

    expect($html)->toContain('<a href="'.$edit.'" class="raqam-cover-link"  tabindex="-1" aria-hidden="true" >')
        // One link per cell: the cover's cell is not wrapped in the row's link (a link inside a link).
        ->and(listCellTag($html, 'cover'))->toBe('div')
        ->and(DB::table('posts')->where('id', $id)->value('cover_media_id'))->toBe($picture);
});

test('the «عاجل» and «مميز» cells are not links: on a row where they are empty, a link would have no name', function () {
    insertPost(['title' => 'خبر عادي']);

    $html = Livewire::test(ListPosts::class)->html();

    foreach (['breaking', 'is-featured'] as $cell) {
        expect(listCellTag($html, $cell))->toBe('div', $cell);
    }
});

/** The element that holds a list cell's content: `a` when the cell is the row's link, `div` when it is not. */
function listCellTag(string $html, string $cell): string
{
    expect(preg_match('~fi-ta-cell-'.$cell.'".*?<(a|div)\s[^>]*class="fi-ta-col"~s', $html, $m))->toBe(1, $cell);

    return $m[1];
}
