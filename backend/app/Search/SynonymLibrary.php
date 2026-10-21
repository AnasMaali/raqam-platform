<?php

namespace App\Search;

use App\Audit\AuditLogger;
use App\Jobs\PushSynonyms;
use Illuminate\Support\Facades\DB;

/**
 * Saves the admin's synonym groups (10.9) in synonym_groups / synonym_terms. A word is written as the admin typed
 * it (trimmed), but compared in its search form: «الشركة» and «شركة» are the same word, so they cannot stand in
 * two groups, nor twice in one. Every change is logged and then sent to the search engine.
 */
final class SynonymLibrary
{
    public const MIN_TERMS = 2;

    public const MAX_TERMS = 20;

    public const MAX_LENGTH = 80;

    /**
     * Creates a group (no id) or replaces the words of one.
     *
     * @param  list<mixed>  $terms
     */
    public function save(?int $groupId, array $terms, ?int $userId = null): int
    {
        $terms = $this->clean($terms);

        return DB::transaction(function () use ($groupId, $terms, $userId) {
            // One writer at a time: two admins saving the same word into two groups must not both pass the check.
            DB::statement('LOCK TABLE synonym_terms IN SHARE ROW EXCLUSIVE MODE');

            $before = $groupId === null ? [] : DB::table('synonym_terms')->where('group_id', $groupId)->orderBy('term')->pluck('term')->all();
            if ($groupId !== null && ! DB::table('synonym_groups')->where('id', $groupId)->lockForUpdate()->exists()) {
                throw new SynonymRejected('هذه المجموعة لم تعد موجودة.');
            }

            $taken = DB::table('synonym_terms')->when($groupId, fn ($q) => $q->where('group_id', '<>', $groupId))->pluck('term');
            $forms = $taken->mapWithKeys(fn (string $t) => [SearchForm::text($t) => $t]);
            foreach ($terms as $term) {
                if ($other = $forms->get(SearchForm::text($term))) {
                    throw new SynonymRejected("الكلمة «{$term}» موجودة في مجموعة أخرى (بصيغة «{$other}»).");
                }
            }

            $groupId ??= (int) DB::table('synonym_groups')->insertGetId([]);
            DB::table('synonym_terms')->where('group_id', $groupId)->delete();
            DB::table('synonym_terms')->insert(array_map(fn (string $t) => ['term' => $t, 'group_id' => $groupId], $terms));

            sort($terms);
            if ($before !== $terms) {
                AuditLogger::record($before === [] ? 'create' : 'update', 'synonym_groups', $groupId, ['terms' => [implode('، ', $before) ?: null, implode('، ', $terms)]], $userId);
            }
            PushSynonyms::dispatch()->afterCommit();

            return $groupId;
        });
    }

    public function delete(int $groupId, ?int $userId = null): void
    {
        DB::transaction(function () use ($groupId, $userId) {
            $before = DB::table('synonym_terms')->where('group_id', $groupId)->orderBy('term')->pluck('term')->all();
            if (DB::table('synonym_groups')->where('id', $groupId)->delete() > 0) {   // its words go with it (cascade)
                AuditLogger::record('delete', 'synonym_groups', $groupId, ['terms' => [implode('، ', $before), null]], $userId);
                PushSynonyms::dispatch()->afterCommit();
            }
        });
    }

    /**
     * @param  list<mixed>  $terms
     * @return list<string>
     */
    private function clean(array $terms): array
    {
        $clean = [];
        $forms = [];
        foreach ($terms as $term) {
            if (! is_string($term)) {
                throw new SynonymRejected('كل كلمة يجب أن تكون نصاً.');
            }
            $term = trim(preg_replace('/\s+/u', ' ', $term));
            if ($term === '') {
                continue;
            }
            if (mb_strlen($term) > self::MAX_LENGTH) {
                throw new SynonymRejected('الكلمة أطول من '.self::MAX_LENGTH.' حرفاً.');
            }
            // Letters, digits, marks, spaces, apostrophe and hyphen: a word, not a pattern or markup.
            if (preg_match("/^[\\p{L}\\p{M}\\p{N}\\x{0640} '\\-]+$/u", $term) !== 1 || SearchForm::text($term) === '') {
                throw new SynonymRejected("«{$term}» ليست كلمة: استعمل الحروف والأرقام فقط.");
            }
            $form = SearchForm::text($term);
            if (isset($forms[$form])) {
                throw new SynonymRejected("«{$term}» و«{$forms[$form]}» نفس الكلمة للبحث؛ اترك واحدة منهما.");
            }
            $forms[$form] = $term;
            $clean[] = $term;
        }

        if (count($clean) < self::MIN_TERMS) {
            throw new SynonymRejected('المجموعة تحتاج كلمتين مختلفتين على الأقل.');
        }
        if (count($clean) > self::MAX_TERMS) {
            throw new SynonymRejected('المجموعة لا تزيد عن '.self::MAX_TERMS.' كلمة.');
        }

        return $clean;
    }
}
