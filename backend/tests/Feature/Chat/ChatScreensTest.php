<?php

use App\Chat\BotStats;
use App\Filament\Resources\BotEntries\Pages\CreateBotEntry;
use App\Filament\Resources\BotEntries\Pages\EditBotEntry;
use App\Filament\Resources\BotQuestions\BotQuestionResource;
use App\Filament\Resources\BotQuestions\Pages\ListBotQuestions;
use App\Filament\Resources\BotQuickReplies\Pages\ManageBotQuickReplies;
use App\Filament\Resources\Faqs\Pages\CreateFaq;
use App\Filament\Resources\Faqs\Pages\EditFaq;
use App\Models\BotMessage;
use App\Models\Faq;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['raqam.api.site_token' => 'chat-token']);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
});

function botAsk(string $message, string $ip = '203.0.113.7')
{
    return test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => 'chat-token', 'X-Raqam-Visitor-Ip' => $ip])->postJson('/api/v1/chat/messages', ['message' => $message]);
}

test('an entry with wordings, keywords, an action and follow-up buttons is made from the screen, and answers at once', function () {
    $button = DB::table('bot_quick_replies')->insertGetId(['label_ar' => 'الطقس', 'action_type' => 'weather', 'sort_order' => 1]);
    Livewire::test(CreateBotEntry::class)->fillForm([
        'question' => 'ما هي ساعات الدوام؟', 'answer' => '<p>من 8 حتى 4، <a href="/contact">تواصل معنا</a>.</p>',
        'variants' => ['متى تفتحون'], 'keywords' => ['دوام'], 'action_type' => 'none', 'followups' => [$button], 'is_active' => true,
    ])->call('create')->assertHasNoFormErrors();

    $reply = botAsk('متى تفتحون؟')->assertOk()->json('data.reply');
    expect($reply['kind'])->toBe('answer')->and($reply['html'])->toContain('href="/contact"')->and($reply['buttons'])->toBe([['id' => $button, 'label' => 'الطقس']])
        ->and(botAsk('الدوام بكرة')->json('data.reply.kind'))->toBe('answer')
        ->and(DB::table('audit_log')->where('subject_table', 'bot_entries')->count())->toBe(1);
});

test('the screen refuses a link that is not https, a keyword that is only a question word, and too many buttons', function (array $bad, string $field) {
    Livewire::test(CreateBotEntry::class)->fillForm(array_merge(['question' => 'سؤال', 'answer' => '<p>جواب</p>', 'action_type' => 'none'], $bad))
        ->call('create')->assertHasFormErrors([$field]);
})->with([
    'http link' => [['action_type' => 'url', 'action_url' => 'http://example.com'], 'action_url'],
    'question word only' => [['keywords' => ['كيف']], 'keywords'],
    'no answer' => [['answer' => '<p></p>'], 'answer'],
]);

test('an entry from the FAQ page keeps its question and answer there; its wordings are edited here', function () {
    $faq = Faq::query()->create(['question' => 'هل المحتوى مجاني؟', 'answer' => 'نعم.', 'is_published' => true]);
    $entry = DB::table('bot_entries')->where('faq_id', $faq->id)->value('id');

    Livewire::test(EditBotEntry::class, ['record' => $entry])->assertSee('السؤال والجواب يُعدّلان هناك')->assertFormFieldIsHidden('question')
        ->fillForm(['variants' => ['بكم الاشتراك'], 'action_type' => 'none'])->call('save')->assertHasNoFormErrors();

    expect(botAsk('بكم الاشتراك')->json('data.reply.html'))->toContain('نعم.');
});

test('the FAQ screen makes questions that the FAQ page and the chatbot show at once', function () {
    Livewire::test(CreateFaq::class)->fillForm(['question' => 'أين مقركم؟', 'answer' => 'في نابلس.', 'is_published' => true])->call('create')->assertHasNoFormErrors();

    $this->getJson('/api/v1/faqs')->assertOk()->assertJsonPath('data.0.question', 'أين مقركم؟');
    expect(botAsk('وين مقركم')->json('data.reply.html'))->toContain('في نابلس.');

    Livewire::test(EditFaq::class, ['record' => Faq::query()->value('id')])->fillForm(['answer' => 'في رفيديا، نابلس.'])->call('save');
    expect(botAsk('أين مقركم؟', '198.51.100.2')->json('data.reply.html'))->toContain('رفيديا');
});

test('a button keeps only the target of its action', function () {
    Livewire::test(ManageBotQuickReplies::class)->callAction('create', ['label_ar' => 'صفحتنا', 'action_type' => 'url', 'target_url' => 'https://raqam.example/x', 'is_root' => true, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(DB::table('bot_quick_replies')->first(['label_ar', 'action_type', 'target_url', 'target_entry_id']))
        ->toEqual((object) ['label_ar' => 'صفحتنا', 'action_type' => 'url', 'target_url' => 'https://raqam.example/x', 'target_entry_id' => null])
        ->and($this->getJson('/api/v1/chat')->json('data.buttons.0.label'))->toBe('صفحتنا');
});

test('an unanswered question becomes an answer in one step, and it and its look-alikes leave the list (18.10)', function () {
    botAsk('هل عندكم تدريب للطلاب؟');
    botAsk('هل عندكم تدريب للطلاب', '198.51.100.3');
    botAsk('سؤال آخر غريب جداً', '198.51.100.4');
    expect(BotQuestionResource::getNavigationBadge())->toBe('3');
    $first = BotMessage::query()->where('role', 'user')->orderBy('id')->first();

    Livewire::test(ListBotQuestions::class)->assertSee('هل عندكم تدريب للطلاب؟')
        ->callAction(TestAction::make('answer')->table($first), ['answer' => '<p>نعم، تواصل معنا.</p>', 'action_type' => 'whatsapp', 'keywords' => ['تدريب']])
        ->assertHasNoFormErrors();

    expect(DB::table('bot_entries')->where('question', 'هل عندكم تدريب للطلاب؟')->value('action_type'))->toBe('whatsapp')
        ->and(BotQuestionResource::getNavigationBadge())->toBe('1')
        ->and(botAsk('تدريب طلاب', '198.51.100.5')->json('data.reply.kind'))->toBe('answer');

    Livewire::test(ListBotQuestions::class)->callAction(TestAction::make('ignore')->table(BotMessage::query()->where('content', 'سؤال آخر غريب جداً')->first()));
    expect(BotQuestionResource::getNavigationBadge())->toBeNull();
});

test('the numbers: conversations, questions, the share answered, and the most asked (18.11)', function () {
    $this->artisan('bot:install');
    botAsk('كيف أعلن معكم؟', '198.51.100.11');
    botAsk('كيف أعلن معكم', '198.51.100.12');
    botAsk('سؤال بلا جواب أبداً', '198.51.100.13');
    $this->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => 'chat-token', 'X-Raqam-Visitor-Ip' => '198.51.100.14'])
        ->postJson('/api/v1/chat/messages', ['button' => DB::table('bot_quick_replies')->value('id')])->assertOk();

    expect(BotStats::totals(7))->toBe(['conversations' => 4, 'questions' => 3, 'answered' => 2, 'rate' => '66.7', 'buttons' => 1])
        ->and(BotStats::top(7)[0])->toBe(['question' => 'كيف أعلن معكم', 'count' => 2, 'answered' => true]);
    Livewire::test(ListBotQuestions::class)->assertSee('أُجيب منها: 2 (66.7%)');
});

test('a visitor\'s question is escaped on the screens', function () {
    DB::table('bot_conversations')->insert(['session_hash' => str_repeat('a', 64)]);
    DB::table('bot_messages')->insert(['conversation_id' => DB::table('bot_conversations')->value('id'), 'role' => 'user', 'content' => '<img src=x onerror=alert(1)>', 'match_score' => 0]);

    Livewire::test(ListBotQuestions::class)->assertSee('<img src=x onerror=alert(1)>', escape: true)->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
});
