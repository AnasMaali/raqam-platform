<?php

use App\Analytics\MissingPages;
use App\Audit\Audited;
use App\Chat\BotLibrary;
use App\Filament\Resources\BotEntries\Pages\ListBotEntries;
use App\Filament\Resources\BotQuickReplies\Pages\ManageBotQuickReplies;
use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\BotEntry;
use App\Models\BotMessage;
use App\Models\BotQuickReply;
use App\Models\EmailLog;
use App\Models\LoginAttempt;
use App\Models\NewsletterIssue;
use App\Models\NotFoundPath;
use App\Models\Subscriber;
use App\Models\SynonymGroup;
use App\Models\User;
use App\Newsletter\NewsletterIssues;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/* 22.14: every add, edit and delete made in the panel is in the audit trail. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin = User::factory()->staff()->create());
});

function auditedChanges(string $table): array
{
    return DB::table('audit_log')->where('subject_table', $table)->orderBy('id')->pluck('action')->all();
}

test('every screen of the panel writes through an audited model, or its writes are audited by the service named here', function () {
    $byService = [
        AdminNotification::class => 'the admin\'s own bell (read/unread), not platform data',
        AuditLog::class => 'the trail itself, read only',
        LoginAttempt::class => 'a log, read only',
        EmailLog::class => 'a log, read only',
        BotMessage::class => 'visitors\' words; turning one into an answer goes through BotLibrary (audited)',
        NewsletterIssue::class => 'NewsletterIssues::save audits the subject, intro and items',
        NotFoundPath::class => 'MissingPages audits each path marked handled or reopened',
        Subscriber::class => 'Subscribers::delete and SubscriberExport audit their work',
        SynonymGroup::class => 'SynonymBook audits each group before and after',
    ];
    foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
        $resource = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');
        $model = $resource::getModel();
        expect(in_array(Audited::class, class_uses_recursive($model), true) || isset($byService[$model]))->toBeTrue("{$resource}: {$model} is not audited");
    }
});

test('the chatbot buttons: adding, editing and deleting one is audited', function () {
    Livewire::test(ManageBotQuickReplies::class)->callAction('create', ['label_ar' => 'صفحتنا', 'action_type' => 'url', 'target_url' => 'https://raqam.example/x', 'is_root' => true, 'is_active' => true])
        ->assertHasNoFormErrors();
    $button = BotQuickReply::query()->firstOrFail();
    Livewire::test(ManageBotQuickReplies::class)->callTableAction('edit', $button, ['label_ar' => 'صفحتنا الجديدة', 'action_type' => 'url', 'target_url' => 'https://raqam.example/y', 'is_root' => true, 'is_active' => true]);
    Livewire::test(ManageBotQuickReplies::class)->callTableAction('delete', $button->fresh());

    expect(auditedChanges('bot_quick_replies'))->toBe(['create', 'update', 'delete']);
});

test('deleting a chatbot answer in the panel is audited', function () {
    $id = app(BotLibrary::class)->save(null, ['question' => 'سؤال للحذف', 'answer' => '<p>جواب</p>', 'action_type' => 'none'], $this->admin->id);

    Livewire::test(ListBotEntries::class)->callTableAction('delete', BotEntry::query()->find($id));

    expect(DB::table('bot_entries')->where('id', $id)->exists())->toBeFalse()->and(auditedChanges('bot_entries'))->toContain('delete');
});

test('editing a newsletter issue is audited with what changed, and saving it unchanged is not', function () {
    $a = insertPost(publishedPostValues());
    $b = insertPost(publishedPostValues());
    $id = DB::table('newsletter_issues')->insertGetId(['subject' => 'نشرة الأسبوع', 'status' => 'draft', 'week_start' => now()->startOfWeek(CarbonInterface::SATURDAY)->toDateString()]);
    DB::table('newsletter_issue_posts')->insert(['issue_id' => $id, 'post_id' => $a, 'sort_order' => 0]);

    app(NewsletterIssues::class)->save($id, 'نشرة الأسبوع', null, [$a], $this->admin->id);
    expect(auditedChanges('newsletter_issues'))->toBe([]);

    app(NewsletterIssues::class)->save($id, 'عنوان جديد', null, [$b, $a], $this->admin->id);
    $fields = DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_log_changes.audit_id')->where('subject_table', 'newsletter_issues')->pluck('field')->all();
    expect(auditedChanges('newsletter_issues'))->toBe(['update'])->and($fields)->toEqualCanonicalizing(['subject', 'posts']);
});

test('marking a missing page handled or reopening it is audited, once per page that changed', function () {
    DB::table('not_found_paths')->insert([['path' => '/a', 'hits' => 2], ['path' => '/b', 'hits' => 1]]);

    MissingPages::resolve('/a', '/b');
    MissingPages::resolve('/a');                 // already handled: nothing changes, nothing written
    MissingPages::reopen('/a');

    // The path is the row's key (not a number), so it is the change itself; the row has no subject id.
    expect(DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_log_changes.audit_id')->whereIn('field', ['resolved_path', 'reopened_path'])
        ->orderBy('audit_id')->get(['action', 'actor_user_id', 'field', 'new_value'])->map(fn ($r) => "{$r->action}:{$r->actor_user_id}:{$r->field}:{$r->new_value}")->all())
        ->toBe(["update:{$this->admin->id}:resolved_path:/a", "update:{$this->admin->id}:resolved_path:/b", "update:{$this->admin->id}:reopened_path:/a"]);
});
