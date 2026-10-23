<?php

use App\Chat\BotLibrary;
use App\Chat\ChatAnswer;
use App\Chat\ChatGuard;
use App\Chat\ChatResponder;
use App\Chat\KnowledgeBase;
use App\Markets\RateFetcher;
use App\Models\Faq;
use App\Weather\WeatherFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const CHAT_TOKEN = 'chat-site-token';

beforeEach(function () {
    Cache::flush();
    config(['raqam.api.site_token' => CHAT_TOKEN]);
    DB::table('settings')->updateOrInsert(['key' => 'contact.whatsapp'], ['value' => '+970599123456', 'value_type' => 'phone']);
    $this->artisan('bot:install')->assertSuccessful();
});

function chat(array $body, string $ip = '203.0.113.7', bool $site = true)
{
    return test()->flushHeaders()->withHeaders($site ? ['X-Raqam-Site-Token' => CHAT_TOKEN, 'X-Raqam-Visitor-Ip' => $ip] : ['X-Raqam-Visitor-Ip' => $ip])
        ->postJson('/api/v1/chat/messages', $body);
}

function ask(string $message, ?string $conversation = null, string $ip = '203.0.113.7')
{
    return chat(array_filter(['message' => $message, 'conversation' => $conversation, 'path' => '/news/1/x']), $ip);
}

test('the chat says it is on, with its welcome and the five first buttons (18.1, 18.6)', function () {
    $c = $this->getJson('/api/v1/chat')->assertOk()->json('data');
    validateAgainstPath(['data' => $c], '/chat');

    expect($c['enabled'])->toBeTrue()->and($c['max_length'])->toBe(500)
        ->and(collect($c['buttons'])->pluck('label')->all())->toBe(['الخدمات والأسعار', 'أعلن معنا', 'العملات اليوم', 'الطقس', 'تواصل معنا']);

    DB::table('feature_flags')->where('key', 'chatbot')->update(['is_enabled' => false]);
    Cache::flush();
    expect($this->getJson('/api/v1/chat')->json('data'))->toMatchArray(['enabled' => false, 'buttons' => []]);
    ask('مرحبا')->assertStatus(404)->assertJsonPath('error.code', 'chatbot_off');
});

test('bot:install runs twice safely and never touches what the admin changed', function () {
    DB::table('bot_entries')->where('question', 'كيف أعلن معكم؟')->update(['answer' => '<p>معدّل</p>']);
    $this->artisan('bot:install')->expectsOutputToContain('Added 0 entries.')->assertSuccessful();

    expect(DB::table('bot_quick_replies')->count())->toBe(5)
        ->and(DB::table('bot_entries')->where('question', 'كيف أعلن معكم؟')->value('answer'))->toBe('<p>معدّل</p>');
});

test('a question finds its answer whatever the hamza, «ال» or wording (the search rules)', function (string $question) {
    $r = ask($question)->assertOk();
    validateAgainstPath($r->json(), '/chat/messages', 'post', 200);

    expect($r->json('data.reply.kind'))->toBe('answer')->and($r->json('data.reply.html'))->toContain('href="/advertise"');
})->with(['كيف أعلن معكم؟', 'كيف اعلن معكم', 'بدي أعلن', 'أسعار الإعلانات', 'عندكم اعلانات؟']);

test('a keyword alone is enough, and a question about something else is not taken for it', function () {
    expect(ask('الطقس بكرة')->json('data.reply.kind'))->toBe('live')
        ->and(ask('هل في رياح قوية')->json('data.reply.kind'))->toBe('live')   // only the keyword «رياح» ties it to the weather
        ->and(ask('ما هي عاصمة اليابان')->json('data.reply.kind'))->toBe('no_answer')
        // One word in common with «أسعار العملات اليوم» is not enough to answer about gold.
        ->and(ask('أسعار الذهب في نابلس')->json('data.reply.kind'))->toBe('no_answer');
});

test('the guard itself refuses more than 500 characters, links and code', function () {
    expect(ChatGuard::refusal(str_repeat('ا', 500)))->toBeNull()
        ->and(ChatGuard::refusal(str_repeat('ا', 501)))->toContain('500')
        ->and(ChatGuard::refusal('اكتب لي sami@example.com'))->toBeNull();   // an address is taken out, not refused
});

test('currencies and weather are answered live with today\'s numbers (18.7)', function () {
    fakeBoi(boiBody(['USD' => '3.106', 'JOD' => '4.3812', 'EUR' => '3.4822']));
    app(RateFetcher::class)->run();
    config(['raqam.weather.key' => 'ow-test-key']);
    fakeOpenWeather(owCurrent(['main' => ['temp' => 24.6, 'humidity' => 40], 'weather' => [['id' => 800, 'icon' => '01d']]]));
    app(WeatherFetcher::class)->city((int) DB::table('cities')->where('slug', 'جنين')->value('id'));

    $usd = ask('كم سعر الدولار اليوم؟')->json('data.reply');
    expect($usd['kind'])->toBe('live')->and($usd['html'])->toContain('دولار')->not->toContain('يورو')
        ->and(collect($usd['links'])->pluck('url')->all())->toBe(['/currencies']);
    expect(ask('أسعار العملات')->json('data.reply.html'))->toContain('دينار')->toContain('يورو');

    $jenin = ask('كيف الطقس في جنين')->json('data.reply');
    expect($jenin['html'])->toContain('طقس جنين الآن: 25°')->and($jenin['links'][0]['url'])->toBe('/weather/جنين');
});

test('the buttons answer from the knowledge base and are not questions', function () {
    $button = DB::table('bot_quick_replies')->where('label_ar', 'تواصل معنا')->value('id');
    $r = chat(['button' => $button])->assertOk()->json('data.reply');

    expect($r['whatsapp'])->toStartWith('https://wa.me/970599123456?text=')
        ->and(DB::table('bot_messages')->where('role', 'user')->first(['content', 'match_score', 'matched_entry_id']))
        ->toMatchObject(['content' => 'تواصل معنا', 'match_score' => null])
        ->and(DB::table('bot_messages')->where('role', 'user')->value('matched_entry_id'))->not->toBeNull();
    chat(['button' => 999999])->assertStatus(404);
});

test('without an answer: the closest published posts and WhatsApp with the question (18.5, 18.8)', function () {
    $post = insertPost(publishedPostValues(['title' => 'افتتاح مصنع الألبان الجديد في نابلس']));
    insertPost(['title' => 'مسودة عن مصنع الألبان']);

    $r = ask('متى افتتح مصنع الألبان؟')->json('data.reply');
    expect($r['kind'])->toBe('no_answer')->and(collect($r['links'])->pluck('label')->all())->toBe(['افتتاح مصنع الألبان الجديد في نابلس'])
        ->and(urldecode($r['whatsapp']))->toContain('سؤالي: متى افتتح مصنع الألبان؟');
    expect(DB::table('bot_messages')->where('role', 'user')->first(['matched_entry_id', 'reviewed_at']))->toEqual((object) ['matched_entry_id' => null, 'reviewed_at' => null]);
});

test('the FAQ page\'s questions answer by themselves; hiding or deleting one takes it out (18.4)', function () {
    $faq = Faq::query()->create(['question' => 'هل المنصة مجانية للقراء؟', 'answer' => "نعم، كل المحتوى مجاني.\n<b>بدون اشتراك</b>", 'is_published' => true]);

    $r = ask('هل المنصة مجانية؟')->json('data.reply');
    expect($r['kind'])->toBe('answer')->and($r['html'])->toContain('نعم، كل المحتوى مجاني.<br />')->toContain('&lt;b&gt;بدون اشتراك')->not->toContain('<b>');

    $faq->update(['is_published' => false]);
    expect(ask('هل المنصة مجانية للقراء؟')->json('data.reply.kind'))->toBe('no_answer');
    $faq->delete();
    expect(DB::table('bot_entries')->whereNotNull('faq_id')->count())->toBe(0);

    // A service's own FAQ stays on its page only.
    $service = DB::table('services')->insertGetId(['group_id' => DB::table('service_groups')->value('id'), 'name_ar' => 'خدمة', 'slug' => 'svc', 'summary' => 'x', 'price_mode' => 'on_request']);
    Faq::query()->create(['service_id' => $service, 'question' => 'متى تبدأ الخدمة؟', 'answer' => 'فوراً.', 'is_published' => true]);
    expect(DB::table('bot_entries')->whereNotNull('faq_id')->count())->toBe(0);
    $this->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(0, 'data');
});

test('the conversation keeps no personal data: no address, and e-mails and phones are taken out (18.9)', function () {
    $first = ask('بريدي sami@example.com ورقمي 0599 123 456 كيف أعلن؟')->json('data.conversation');
    ask('شكراً', $first)->assertOk();

    $stored = DB::table('bot_messages')->where('role', 'user')->pluck('content')->implode(' | ');
    expect($stored)->toContain('[بريد]')->toContain('[رقم]')->not->toContain('sami@')->not->toContain('123 456')
        ->and(DB::table('bot_conversations')->count())->toBe(1)
        ->and(json_encode(DB::table('bot_conversations')->get()))->not->toContain('203.0.113.7')
        ->and(DB::table('bot_conversations')->value('session_hash'))->not->toBe(explode('.', $first)[1]);
});

test('a conversation continues only with its own token; a forged one starts a new one', function () {
    $token = ask('مرحبا')->json('data.conversation');
    expect($token)->toMatch('/^[0-9a-f-]{36}\.[0-9a-f]{64}$/');

    ask('ثاني', $token)->assertOk()->assertJsonPath('data.conversation', $token);
    $forged = explode('.', $token)[0].'.'.str_repeat('a', 64);
    expect(ask('ثالث', $forged)->json('data.conversation'))->not->toBe($token)->and(DB::table('bot_conversations')->count())->toBe(2);
});

test('links and code are refused and nothing of them is kept; 500 characters at most (18.12)', function (string $message, string $code) {
    ask($message)->assertStatus(422)->assertJsonPath('error.code', $code);
    expect(DB::table('bot_messages')->count())->toBe(0);
})->with([
    'a link' => ['شوف https://evil.example', 'not_allowed'],
    'www' => ['زوروا www.evil.example', 'not_allowed'],
    'a domain' => ['موقعي evil.com', 'not_allowed'],
    'a script' => ['<script>alert(1)</script>', 'not_allowed'],
    'a tag' => ['<img src=x onerror=alert(1)>', 'not_allowed'],
    'template' => ['{{ 7*7 }}', 'not_allowed'],
    'sql' => ["1' union select password from users", 'not_allowed'],
    'too long' => [str_repeat('ا', 501), 'not_allowed'],
    'empty' => ['   ', 'empty'],
]);

test('ten messages a minute per visitor, by its /64 for IPv6', function () {
    foreach (range(1, 10) as $i) {
        ask('سؤال '.$i, ip: '2001:db8:9:9::'.$i)->assertOk();
    }
    ask('سؤال 11', ip: '2001:db8:9:9::ff')->assertStatus(429)->assertJsonPath('error.code', 'too_many');
    ask('سؤال 12', ip: '2001:db8:9:8::1')->assertOk();
});

test('only the site server may send, in JSON', function () {
    chat(['message' => 'مرحبا'], site: false)->assertStatus(403);
    $this->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => CHAT_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7'])->post('/api/v1/chat/messages', ['message' => 'x'])->assertStatus(415);
});

test('an answer written in the panel is cleaned: no scripts, no handlers, links only https or of the site', function () {
    $id = app(BotLibrary::class)->save(null, ['question' => 'سؤال الأمان التجريبي', 'action_type' => 'none',
        'answer' => '<p onclick="x()">جواب <script>alert(1)</script><a href="javascript:alert(1)">أ</a> <a href="//evil.example">ب</a> <a href="/services">ج</a> <a href="https://bank.example">د</a><img src=x></p>'], null);
    $stored = DB::table('bot_entries')->where('id', $id)->value('answer');
    $shown = ask('سؤال الأمان التجريبي')->json('data.reply.html');

    foreach ([$stored, $shown] as $html) {
        expect($html)->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('//evil.example')->not->toContain('<img')
            ->toContain('href="/services"')->toContain('href="https://bank.example"');
    }
});

test('the knowledge base sits behind an interface: another responder answers through the same API (18.13)', function () {
    app()->bind(ChatResponder::class, fn () => new class implements ChatResponder
    {
        public function answer(string $question): ChatAnswer
        {
            return new ChatAnswer(ChatAnswer::ANSWER, '<p>من مزوّد آخر</p>', entryId: null, score: 0.9);
        }

        public function press(int $quickReplyId): ?ChatAnswer
        {
            return null;
        }
    });

    expect(ask('أي شي')->json('data.reply.html'))->toBe('<p>من مزوّد آخر</p>');
});

test('KnowledgeBase words drop question words and fold forms like the search', function () {
    expect(KnowledgeBase::words('كيف أعلن في المنصة؟'))->toBe(KnowledgeBase::words('اعلن بالمنصه'))
        ->and(KnowledgeBase::words('ما هو'))->toBe([])
        ->and(KnowledgeBase::dice(['a', 'b'], ['a', 'b']))->toBe(1.0)->and(KnowledgeBase::dice(['a'], ['b']))->toBe(0.0);
});
