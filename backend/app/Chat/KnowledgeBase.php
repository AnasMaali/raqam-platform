<?php

namespace App\Chat;

use App\Search\SearchForm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The chatbot's knowledge (18.3, 18.4, 18.5): the admin's entries (a question in several wordings, keywords, the answer)
 * and the questions of the FAQ page, each read in the search form (hamza, ta marbuta, diacritics, «ال», plurals: the
 * same rules as the search, decision 10). Kept in the cache until an entry changes (`bot:version`).
 *
 * A question matches the wording it shares most words with (Dice coefficient over the words that carry meaning); a
 * keyword found in the question counts as a good match on its own. Below MATCH there is no answer.
 */
final class KnowledgeBase
{
    public const MATCH = 0.5;

    public const KEYWORD = 0.6;

    /** Question words and fillers that say nothing about the subject (in search form when used). */
    private const STOP = ['ما', 'ماذا', 'هل', 'كيف', 'كم', 'متى', 'اين', 'أين', 'وين', 'من', 'في', 'على', 'عن', 'الى', 'إلى', 'هو', 'هي',
        'انا', 'أنا', 'انت', 'أنت', 'لو', 'ممكن', 'بدي', 'اريد', 'أريد', 'عندكم', 'عندك', 'لكم', 'لي', 'يا', 'و', 'او', 'أو', 'شو', 'ايش',
        'قديش', 'مع', 'هذا', 'هذه', 'ذلك', 'لو سمحت', 'سمحت', 'مرحبا', 'مرحباً', 'السلام', 'عليكم', 'اهلا', 'أهلاً', 'بكم', 'لدي', 'لديكم', 'الذي', 'التي'];

    /** @return list<string> the words of a text that carry meaning, in search form */
    public static function words(string $text): array
    {
        static $stop = null;
        $stop ??= array_flip(array_map(fn ($w) => SearchForm::text($w), self::STOP));

        return array_values(array_unique(array_filter(SearchForm::of($text)->words(), fn ($w) => ! isset($stop[$w]))));
    }

    /** @return array{entry: array<string, mixed>, score: float}|null the best entry, when it is close enough */
    public function match(string $question): ?array
    {
        $words = self::words($question);
        if ($words === []) {
            return null;
        }
        $best = null;
        foreach ($this->entries() as $entry) {
            $score = 0.0;
            foreach ($entry['phrases'] as $phrase) {
                $score = max($score, self::dice($words, $phrase));
            }
            foreach ($entry['keywords'] as $keyword) {
                if ($keyword !== [] && array_diff($keyword, $words) === []) {
                    $score = max($score, self::KEYWORD);
                }
            }
            if ($best === null || $score > $best['score']) {
                $best = ['entry' => $entry, 'score' => $score];
            }
        }

        return $best !== null && $best['score'] >= self::MATCH ? $best : null;
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    public static function dice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return round(2 * count(array_intersect($a, $b)) / (count($a) + count($b)), 4);
    }

    /** @return array<string, mixed>|null one entry as the chatbot uses it */
    public function entry(int $id): ?array
    {
        return collect($this->entries())->firstWhere('id', $id);
    }

    /** @return list<array{id: int, question: string, html: string, action: string, target: mixed, followups: list<int>, phrases: list<list<string>>, keywords: list<list<string>>}> */
    public function entries(): array
    {
        $version = (int) Cache::get('bot:version', 0);

        return Cache::remember("bot:kb:{$version}", 3600, function () {
            BotLibrary::syncFaqs();
            $rows = DB::table('bot_entries as e')->leftJoin('faqs as f', 'f.id', '=', 'e.faq_id')
                ->where('e.is_active', true)
                ->where(fn ($q) => $q->whereNull('e.faq_id')->orWhere(fn ($q) => $q->where('f.is_published', true)->whereNull('f.service_id')))
                ->orderBy('e.id')
                ->get(['e.id', 'e.faq_id', 'e.question', 'e.answer', 'e.action_type', 'e.action_service_id', 'e.action_page_id', 'e.action_url', 'f.question as faq_question', 'f.answer as faq_answer']);
            $variants = DB::table('bot_entry_variants')->whereIn('entry_id', $rows->pluck('id'))->get()->groupBy('entry_id');
            $keywords = DB::table('bot_entry_keywords')->whereIn('entry_id', $rows->pluck('id'))->get()->groupBy('entry_id');
            $followups = DB::table('bot_entry_quick_replies as l')->join('bot_quick_replies as q', 'q.id', '=', 'l.quick_reply_id')->where('q.is_active', true)
                ->whereIn('l.entry_id', $rows->pluck('id'))->orderBy('l.sort_order')->get(['l.entry_id', 'q.id'])->groupBy('entry_id');

            return $rows->map(function (object $r) use ($variants, $keywords, $followups) {
                $question = (string) ($r->faq_id ? $r->faq_question : $r->question);
                $phrases = collect([$question])->merge(($variants[$r->id] ?? collect())->pluck('phrase'))
                    ->map(fn ($p) => self::words((string) $p))->filter()->values()->all();

                return [
                    'id' => (int) $r->id, 'question' => $question,
                    'html' => $r->faq_id ? BotAnswerSanitizer::fromText((string) $r->faq_answer) : BotAnswerSanitizer::clean((string) $r->answer),
                    'action' => $r->action_type, 'target' => match ($r->action_type) {
                        'service' => (int) $r->action_service_id, 'page' => (int) $r->action_page_id, 'url' => $r->action_url, default => null,
                    },
                    'followups' => ($followups[$r->id] ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'phrases' => $phrases,
                    'keywords' => ($keywords[$r->id] ?? collect())->map(fn ($k) => self::words((string) $k->keyword))->filter()->values()->all(),
                ];
            })->all();
        });
    }

    /** Called after any change to the entries, the buttons or the FAQ: the next question reads them afresh. */
    public static function changed(): void
    {
        Cache::forever('bot:version', (int) Cache::get('bot:version', 0) + 1);
    }
}
