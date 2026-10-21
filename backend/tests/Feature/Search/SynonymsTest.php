<?php

use App\Console\InstallSynonymsCommand;
use App\Filament\Pages\SearchStatistics;
use App\Filament\Resources\Synonyms\Pages\CreateSynonymGroup;
use App\Filament\Resources\Synonyms\Pages\EditSynonymGroup;
use App\Filament\Resources\Synonyms\Pages\ListSynonymGroups;
use App\Filament\Resources\Synonyms\SynonymGroupResource;
use App\Jobs\PushSynonyms;
use App\Models\SynonymGroup;
use App\Models\User;
use App\Search\Searcher;
use App\Search\SearchIndexer;
use App\Search\SearchQuery;
use App\Search\SynonymLibrary;
use App\Search\SynonymRejected;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->library = app(SynonymLibrary::class);
});

afterEach(fn () => dropSearchIndexes());

function termsOf(int $group): array
{
    return DB::table('synonym_terms')->where('group_id', $group)->orderBy('term')->pluck('term')->all();
}

// ---- the rules -----------------------------------------------------------------------------------

test('a group keeps the words as typed (trimmed) and is logged', function () {
    $id = $this->library->save(null, ['  شيكل ', 'شيقل', ''], $this->admin->id);

    expect(termsOf($id))->toBe(['شيقل', 'شيكل']);
    $audit = DB::table('audit_log')->where('subject_table', 'synonym_groups')->where('subject_id', $id)->first();
    expect($audit->action)->toBe('create')->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and(DB::table('audit_log_changes')->where('audit_id', $audit->id)->value('new_value'))->toBe('شيقل، شيكل');
});

test('the same word in its search form cannot stand twice, in a group or across groups', function (array $first, array $second, string $message) {
    $first !== [] && $this->library->save(null, $first);

    expect(fn () => $this->library->save(null, $second))->toThrow(SynonymRejected::class, $message);
})->with([
    'article' => [[], ['الشركة', 'شركة'], 'نفس الكلمة'],
    'hamza' => [[], ['أسعار', 'اسعار'], 'نفس الكلمة'],
    'ta marbuta' => [[], ['ضريبة', 'ضريبه'], 'نفس الكلمة'],
    'diacritics' => [[], ['سِعر', 'سعر'], 'نفس الكلمة'],
    'in another group' => [['شيكل', 'شيقل'], ['الشيكل', 'عملة'], 'موجودة في مجموعة أخرى'],
]);

test('a group needs two words, at most twenty, plain words of at most 80 letters', function (array $terms, string $message) {
    expect(fn () => $this->library->save(null, $terms))->toThrow(SynonymRejected::class, $message);
    expect(DB::table('synonym_groups')->count())->toBe(0);
})->with([
    'one word' => [['شيكل'], 'كلمتين'],
    'blank words' => [['شيكل', '   ', ''], 'كلمتين'],
    'too many' => [array_map(fn ($i) => 'كلمة'.$i, range(1, 21)), 'لا تزيد'],
    'too long' => [[str_repeat('ب', 81), 'شيكل'], 'أطول'],
    'markup' => [['<b>شيكل</b>', 'شيقل'], 'ليست كلمة'],
    'pattern' => [['شيكل*', 'شيقل'], 'ليست كلمة'],
    'only punctuation' => [['$', 'دولار'], 'ليست كلمة'],
    'not text' => [[['شيكل'], 'شيقل'], 'نصاً'],
]);

test('editing replaces the words of the group; its own words do not count as taken', function () {
    $id = $this->library->save(null, ['شيكل', 'شيقل']);

    $this->library->save($id, ['شيكل', 'شيقل', 'الشيكل الجديد']);

    expect(termsOf($id))->toBe(['الشيكل الجديد', 'شيقل', 'شيكل'])
        ->and(DB::table('audit_log')->where('subject_id', $id)->where('action', 'update')->count())->toBe(1);
});

test('saving takes a lock on the words, so two admins cannot put one word in two groups at the same moment', function () {
    // A second connection is in the middle of writing words (the lock every insert takes). Plain inserts would
    // not wait for it; the save's own lock does, which is what keeps two saves from checking at the same time.
    config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
    $other = DB::connection('pgsql_other');
    $other->beginTransaction();
    $other->statement('LOCK TABLE synonym_terms IN ROW EXCLUSIVE MODE');
    DB::statement("SET LOCAL lock_timeout = '300ms'");

    try {
        expect(fn () => $this->library->save(null, ['شيكل', 'شيقل']))->toThrow(QueryException::class, 'lock timeout');
    } finally {
        $other->rollBack();
        DB::disconnect('pgsql_other');
    }
});

test('a group deleted meanwhile is not recreated by an edit', function () {
    $id = $this->library->save(null, ['شيكل', 'شيقل']);
    $this->library->delete($id);

    expect(fn () => $this->library->save($id, ['شيكل', 'شيقل']))->toThrow(SynonymRejected::class, 'لم تعد موجودة')
        ->and(DB::table('synonym_terms')->count())->toBe(0);
});

test('every change is sent to the engine after the commit', function () {
    Queue::fake([PushSynonyms::class]);
    config(['raqam.search.key' => 'k']);

    $id = $this->library->save(null, ['شيكل', 'شيقل']);
    $this->library->save($id, ['شيكل', 'شيقل', 'عملة']);
    $this->library->delete($id);

    Queue::assertPushed(PushSynonyms::class, 3);
});

// ---- the engine ---------------------------------------------------------------------------------------

test('a new synonym finds an item that does not contain the word typed', function () {
    useSearchEngine();
    ensurePostKinds();
    $post = insertPost(publishedPostValues(['title' => 'هبوط سعر الشيقل']));
    SearchIndexer::make()->rebuild('posts');
    $search = fn () => array_column(app(Searcher::class)->search(new SearchQuery('الشيكل'))->posts, 'id');
    expect($search())->toBe([]);

    $this->library->save(null, ['شيكل', 'شيقل']);   // the push runs on the sync queue in tests
    settleSearch();

    expect($search())->toBe([$post]);
});

// ---- the screen ---------------------------------------------------------------------------------------

test('the screen creates, edits and deletes groups, and shows the reason of a refusal on the field', function () {
    Livewire::test(CreateSynonymGroup::class)->fillForm(['terms' => ['دولار', 'دولارات']])->call('create')->assertHasNoFormErrors();
    $group = SynonymGroup::query()->latest('id')->first();
    expect($group->terms())->toBe(['دولار', 'دولارات']);

    Livewire::test(CreateSynonymGroup::class)->fillForm(['terms' => ['الدولار', 'عملة']])->call('create')
        ->assertHasFormErrors(['terms']);
    expect(DB::table('synonym_groups')->count())->toBe(1);

    Livewire::test(EditSynonymGroup::class, ['record' => $group->id])->assertFormSet(['terms' => ['دولار', 'دولارات']])
        ->fillForm(['terms' => ['دولار', 'دولارات', 'الدولار الأمريكي']])->call('save')->assertHasNoFormErrors();
    expect($group->terms())->toBe(['الدولار الأمريكي', 'دولار', 'دولارات']);

    Livewire::test(ListSynonymGroups::class)->assertCanSeeTableRecords([$group])
        ->callAction(TestAction::make('delete')->table($group));
    expect(DB::table('synonym_groups')->count())->toBe(0)->and(DB::table('synonym_terms')->count())->toBe(0);
});

test('the list finds a group by any of its words, whatever the hamza', function () {
    $a = SynonymGroup::find($this->library->save(null, ['أسعار', 'سعر']));
    $b = SynonymGroup::find($this->library->save(null, ['شيكل', 'شيقل']));

    Livewire::test(ListSynonymGroups::class)->searchTable('اسعار')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
});

test('the screens are for signed-in staff only', function () {
    $urls = [SynonymGroupResource::getUrl('index'), SearchStatistics::getUrl()];
    auth()->logout();

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect();
    }
});

// ---- the starter list ----------------------------------------------------------------------------------

test('synonyms:install adds the starter list once, and never touches a word the admin already used', function () {
    $mine = $this->library->save(null, ['شيكل', 'عملة إسرائيلية']);

    $this->artisan('synonyms:install')->assertSuccessful();
    $groups = DB::table('synonym_groups')->count();
    $this->artisan('synonyms:install')->expectsOutputToContain('Added 0')->assertSuccessful();

    expect($groups)->toBe(InstallSynonymsCommand::needed())          // the shekel group is skipped, mine stays
        ->and(termsOf($mine))->toBe(['شيكل', 'عملة إسرائيلية'])
        ->and(DB::table('synonym_groups')->count())->toBe($groups);
});

test('the starter list itself is valid: no word twice, every group saves', function () {
    $this->artisan('synonyms:install')->expectsOutputToContain('Added '.InstallSynonymsCommand::needed().' synonym group(s), skipped 0')->assertSuccessful();
});

test('pairs the feminine-plural fold already joins are left out, and come back when the fold is off', function () {
    expect(InstallSynonymsCommand::needed())->toBe(count(InstallSynonymsCommand::STARTER) - 3);   // شركة، عملة، صناعة

    $this->artisan('synonyms:install')->expectsOutputToContain('3 already one word in search')->assertSuccessful();
    expect(DB::table('synonym_terms')->where('term', 'شركات')->exists())->toBeFalse();

    config(['raqam.search.feminine_plural' => false]);
    DB::table('synonym_groups')->delete();

    $this->artisan('synonyms:install')->expectsOutputToContain('Added '.count(InstallSynonymsCommand::STARTER).' synonym group(s), skipped 0')->assertSuccessful();
    expect(DB::table('synonym_terms')->where('term', 'شركات')->exists())->toBeTrue();
});
