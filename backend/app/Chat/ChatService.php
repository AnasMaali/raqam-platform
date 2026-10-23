<?php

namespace App\Chat;

use App\Support\TrackingHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One exchange with the chatbot (18.9, 18.12, 18.14). A conversation is anonymous: the browser holds a random token,
 * we keep only its HMAC; no name, no address, not even an HMAC of the visitor's address (that one lives an hour in the
 * rate limiter only). Each visitor may send 10 messages a minute and 200 a day; a conversation takes 60 messages and
 * lives a day. A message with a link or code is refused and nothing of it is kept.
 */
final class ChatService
{
    public const PER_MINUTE = 10;

    public const PER_DAY = 200;

    public const PER_CONVERSATION = 60;

    public function __construct(private ChatResponder $responder) {}

    public static function enabled(): bool
    {
        return (bool) DB::table('feature_flags')->where('key', 'chatbot')->value('is_enabled');
    }

    /**
     * @return array{conversation: string, reply: array<string, mixed>}
     *
     * @throws ChatRefused
     */
    public function handle(?string $token, ?string $message, ?int $quickReply, ?string $path, string $visitorIp): array
    {
        if (! self::enabled()) {
            throw new ChatRefused('chatbot_off', 'الشات بوت متوقف الآن.', 404);
        }
        $visitor = TrackingHash::visitor($visitorIp);
        foreach (['minute' => [self::PER_MINUTE, 60], 'day' => [self::PER_DAY, 86400]] as $name => [$max, $seconds]) {
            if (RateLimiter::tooManyAttempts("chat:{$name}:{$visitor}", $max)) {
                throw new ChatRefused('too_many', 'رسائل كثيرة؛ انتظر قليلاً ثم أكمل.', 429);
            }
        }

        $question = null;
        if ($quickReply === null) {
            $question = ChatGuard::tidy((string) $message);
            if ($question === '') {
                throw new ChatRefused('empty', 'اكتب سؤالك.');
            }
            if (($why = ChatGuard::refusal($question)) !== null) {
                throw new ChatRefused('not_allowed', $why);
            }
            $question = ChatGuard::redact($question);
        }
        RateLimiter::hit("chat:minute:{$visitor}", 60);
        RateLimiter::hit("chat:day:{$visitor}", 86400);

        [$conversationId, $token] = $this->conversation($token, $path);
        if (DB::table('bot_messages')->where('conversation_id', $conversationId)->where('role', 'user')->count() >= self::PER_CONVERSATION) {
            [$conversationId, $token] = $this->conversation(null, $path);
        }

        if ($quickReply !== null) {
            $answer = $this->responder->press($quickReply) ?? throw new ChatRefused('not_found', 'هذا الزر غير موجود.', 404);
            $label = (string) DB::table('bot_quick_replies')->where('id', $quickReply)->value('label_ar');
            // A button is not a question: kept with its entry (or marked handled), never in the "unanswered" queue.
            $this->store($conversationId, $label, $answer, button: true);
        } else {
            $answer = $this->responder->answer($question);
            $this->store($conversationId, $question, $answer, button: false);
        }

        return ['conversation' => $token, 'reply' => $answer->toArray()];
    }

    /** @return array{0: int, 1: string} the conversation id and the token the browser keeps */
    private function conversation(?string $token, ?string $path): array
    {
        if (is_string($token) && preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/', $token, $m) === 1) {
            $row = DB::table('bot_conversations')->where('public_id', $m[1])->where('started_at', '>', DB::raw("now() - interval '1 day'"))->first(['id', 'session_hash']);
            if ($row !== null && hash_equals($row->session_hash, self::hash($m[2]))) {
                return [(int) $row->id, $token];
            }
        }
        $publicId = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $path = is_string($path) && preg_match('~^/(?!/)[^\s\\\\]{0,499}$~u', $path) === 1 ? $path : null;
        $id = DB::table('bot_conversations')->insertGetId(['public_id' => $publicId, 'session_hash' => self::hash($secret), 'entry_path' => $path]);

        return [(int) $id, $publicId.'.'.$secret];
    }

    private static function hash(string $secret): string
    {
        return hash_hmac('sha256', 'chat|'.$secret, (string) config('app.key'));
    }

    private function store(int $conversationId, string $question, ChatAnswer $answer, bool $button): void
    {
        DB::transaction(function () use ($conversationId, $question, $answer, $button) {
            DB::table('bot_messages')->insert([
                'conversation_id' => $conversationId, 'role' => 'user', 'content' => mb_substr($question, 0, ChatGuard::MAX),
                'matched_entry_id' => $answer->entryId, 'match_score' => $button ? null : ($answer->score ?? 0),
                'reviewed_at' => $button && $answer->entryId === null ? DB::raw('now()') : null,
            ]);
            DB::table('bot_messages')->insert(['conversation_id' => $conversationId, 'role' => 'bot', 'content' => $answer->text() ?: '—']);
        });
    }
}
