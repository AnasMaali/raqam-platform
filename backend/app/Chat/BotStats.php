<?php

namespace App\Chat;

use Illuminate\Support\Facades\DB;

/**
 * The chatbot's numbers (18.11) over the last N days: conversations, questions typed, the share answered (by the
 * knowledge base or a live answer), and the questions asked most, worded alike counted together. Buttons are not
 * questions and are left out of the share.
 */
final class BotStats
{
    /** How two questions count as the same (SQL): normalised, punctuation and extra spaces dropped ("؟" changes nothing). */
    public static function key(string $column): string
    {
        return "btrim(regexp_replace(regexp_replace(normalize_ar({$column}), '[[:punct:]؟،؛«»]+', ' ', 'g'), '\\s+', ' ', 'g'))";
    }

    /** @return array{conversations: int, questions: int, answered: int, rate: ?string, buttons: int} */
    public static function totals(int $days): array
    {
        $since = DB::raw("now() - make_interval(days => {$days})");
        $questions = DB::table('bot_messages')->where('role', 'user')->whereNotNull('match_score')->where('created_at', '>', $since);
        $total = (clone $questions)->count();
        $answered = (clone $questions)->whereNotNull('matched_entry_id')->count();

        return [
            'conversations' => DB::table('bot_conversations')->where('started_at', '>', $since)->count(),
            'questions' => $total, 'answered' => $answered,
            'rate' => $total === 0 ? null : number_format($answered * 100 / $total, 1),
            'buttons' => DB::table('bot_messages')->where('role', 'user')->whereNull('match_score')->where('created_at', '>', $since)->count(),
        ];
    }

    /** @return list<array{question: string, count: int, answered: bool}> */
    public static function top(int $days, int $limit = 20): array
    {
        return DB::table('bot_messages')->where('role', 'user')->whereNotNull('match_score')->where('created_at', '>', DB::raw("now() - make_interval(days => {$days})"))
            ->groupBy(DB::raw(self::key('content')))->orderByRaw('count(*) DESC')->limit($limit)
            ->get([DB::raw('min(content) AS question'), DB::raw('count(*) AS n'), DB::raw('bool_or(matched_entry_id IS NOT NULL) AS answered')])
            ->map(fn ($r) => ['question' => (string) $r->question, 'count' => (int) $r->n, 'answered' => (bool) $r->answered])->all();
    }
}
