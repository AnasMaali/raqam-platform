<?php

namespace App\Chat;

use App\Audit\AuditLogger;
use App\Search\SearchForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes the chatbot's knowledge (18.3, 18.4, 18.10): entries with their wordings, keywords, action and follow-up
 * buttons; the FAQ page's questions as entries of their own; and an unanswered question turned into an entry in one
 * step. Every change is audited and refreshes the knowledge base.
 */
final class BotLibrary
{
    public const ACTIONS = ['none' => 'بدون', 'service' => 'خدمة', 'page' => 'صفحة', 'url' => 'رابط', 'whatsapp' => 'واتساب', 'rates' => 'أسعار العملات اليوم', 'weather' => 'الطقس الآن'];

    /**
     * @param  array<string, mixed>  $data  question, answer, variants, keywords, action_type, action_service_id, action_page_id, action_url, followups, is_active
     *
     * @throws ValidationException
     */
    public function save(?int $id, array $data, ?int $userId): int
    {
        $faq = $id === null ? null : DB::table('bot_entries')->where('id', $id)->value('faq_id');
        $clean = $this->check($data, $faq !== null, $id);

        $entryId = DB::transaction(function () use ($id, $clean, $faq, $userId) {
            $row = [
                'action_type' => $clean['action_type'], 'action_service_id' => $clean['action_service_id'], 'action_page_id' => $clean['action_page_id'],
                'action_url' => $clean['action_url'], 'is_active' => $clean['is_active'], 'updated_at' => DB::raw('now()'),
            ];
            if ($faq === null) {
                $row += ['question' => $clean['question'], 'answer' => $clean['answer']];
            }
            if ($id === null) {
                $id = DB::table('bot_entries')->insertGetId($row + ['created_by' => $userId]);
            } else {
                DB::table('bot_entries')->where('id', $id)->update($row);
            }
            DB::table('bot_entry_variants')->where('entry_id', $id)->delete();
            DB::table('bot_entry_variants')->insert(array_map(fn ($p) => ['entry_id' => $id, 'phrase' => $p], $clean['variants']));
            DB::table('bot_entry_keywords')->where('entry_id', $id)->delete();
            DB::table('bot_entry_keywords')->insert(array_map(fn ($k) => ['entry_id' => $id, 'keyword' => $k], $clean['keywords']));
            DB::table('bot_entry_quick_replies')->where('entry_id', $id)->delete();
            DB::table('bot_entry_quick_replies')->insert(array_map(fn ($q, $i) => ['entry_id' => $id, 'quick_reply_id' => $q, 'sort_order' => $i], $clean['followups'], array_keys($clean['followups'])));
            AuditLogger::record('update', 'bot_entries', (int) $id, ['question' => [null, $clean['question'] ?? '(FAQ)'], 'variants' => [null, implode('، ', $clean['variants'])],
                'keywords' => [null, implode('، ', $clean['keywords'])], 'action' => [null, $clean['action_type']]], $userId);

            return (int) $id;
        });
        KnowledgeBase::changed();

        return $entryId;
    }

    /**
     * An unanswered question becomes an entry (18.10); it and every unanswered question worded the same way are marked
     * handled.
     *
     * @param  array<string, mixed>  $data
     */
    public function answerQuestion(int $messageId, array $data, ?int $userId): int
    {
        $content = (string) DB::table('bot_messages')->where('id', $messageId)->where('role', 'user')->value('content');
        $id = $this->save(null, $data + ['question' => $content], $userId);
        $this->markHandled($content);

        return $id;
    }

    /** Takes a question, and the ones worded the same way, out of the unanswered queue. */
    public function markHandled(string $content): int
    {
        $same = fn (string $text) => implode(' ', SearchForm::of($text)->words());   // the words only: "؟" changes nothing
        $form = $same($content);
        $ids = DB::table('bot_messages')->where('role', 'user')->whereNull('matched_entry_id')->whereNull('reviewed_at')
            ->get(['id', 'content'])->filter(fn ($m) => $same((string) $m->content) === $form)->pluck('id');

        return DB::table('bot_messages')->whereIn('id', $ids)->update(['reviewed_at' => DB::raw('now()')]);
    }

    /** The FAQ page's published questions (no service) each have an entry (18.4). */
    public static function syncFaqs(): int
    {
        return DB::affectingStatement(
            'INSERT INTO bot_entries (faq_id) SELECT f.id FROM faqs f
             WHERE f.service_id IS NULL AND NOT EXISTS (SELECT 1 FROM bot_entries e WHERE e.faq_id = f.id)',
        );
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function check(array $d, bool $fromFaq, ?int $id): array
    {
        $line = fn ($v) => is_string($v) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)) : '';
        $list = fn ($v, int $max) => collect(is_array($v) ? $v : [])->map($line)->filter()->map(fn ($s) => mb_substr($s, 0, $max))->unique()->values();
        $errors = [];

        $question = $fromFaq ? null : $line($d['question'] ?? '');
        $answer = $fromFaq ? null : BotAnswerSanitizer::clean((string) ($d['answer'] ?? ''));
        if (! $fromFaq && ($question === '' || mb_strlen($question) > 300)) {
            $errors['question'] = 'اكتب السؤال (حتى 300 حرف).';
        }
        if (! $fromFaq && (trim(strip_tags((string) $answer)) === '' || mb_strlen((string) $answer) > BotAnswerSanitizer::MAX_LENGTH)) {
            $errors['answer'] = 'اكتب الجواب (حتى '.BotAnswerSanitizer::MAX_LENGTH.' حرف).';
        }
        $variants = $list($d['variants'] ?? [], 300);
        $keywords = $list($d['keywords'] ?? [], 60);
        if ($variants->count() > 30 || $keywords->count() > 30) {
            $errors['variants'] = 'حتى 30 صيغة و30 كلمة مفتاحية.';
        }
        foreach ($variants->merge($keywords) as $text) {
            if (KnowledgeBase::words($text) === []) {
                $errors['keywords'] ??= '«'.$text.'» لا تحمل معنى للبحث (كلمة سؤال فقط).';
            }
        }

        $action = (string) ($d['action_type'] ?? 'none');
        $service = $page = $url = null;
        if (! array_key_exists($action, self::ACTIONS)) {
            $errors['action_type'] = 'اختر الإجراء.';
        } elseif ($action === 'service') {
            $service = DB::table('services')->where('id', (int) ($d['action_service_id'] ?? 0))->whereNull('deleted_at')->value('id');
            if ($service === null) {
                $errors['action_service_id'] = 'اختر الخدمة.';
            }
        } elseif ($action === 'page') {
            $page = DB::table('pages')->where('id', (int) ($d['action_page_id'] ?? 0))->value('id');
            if ($page === null) {
                $errors['action_page_id'] = 'اختر الصفحة.';
            }
        } elseif ($action === 'url') {
            $url = $line($d['action_url'] ?? '');
            if (preg_match('~^https://[^\s/@]+\.[^\s/@]+(/\S*)?$~i', $url) !== 1 || mb_strlen($url) > 2048) {
                $errors['action_url'] = 'الرابط لازم يبدأ بـ https://';
            }
        }
        $followups = collect(is_array($d['followups'] ?? null) ? $d['followups'] : [])->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
        if ($followups->count() > 6 || DB::table('bot_quick_replies')->whereIn('id', $followups)->count() !== $followups->count()) {
            $errors['followups'] = 'اختر حتى 6 أزرار موجودة.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['question' => $question, 'answer' => $answer, 'variants' => $variants->all(), 'keywords' => $keywords->all(), 'action_type' => $action,
            'action_service_id' => $service ? (int) $service : null, 'action_page_id' => $page ? (int) $page : null, 'action_url' => $action === 'url' ? $url : null,
            'followups' => $followups->all(), 'is_active' => (bool) ($d['is_active'] ?? true)];
    }
}
