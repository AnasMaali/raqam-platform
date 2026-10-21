# Arabic search: how it works and what was measured

Search (decision 10) runs on Meilisearch v1.54.3 with PostgreSQL (pg_trgm) as the fallback. This page
describes the Arabic handling and keeps the measurements behind its choices. Reproduce the figures with a
local engine and `php artisan search:evaluate` (the labelled documents and queries are in
`backend/tests/Fixtures/search`).

## The search form

Every searchable field is indexed in its **search form**, and every query is sent in the same form
(`App\Search\SearchForm`):

1. `ArabicNormalizer` (the same rules as `normalize_ar()` in PostgreSQL): أ/إ/آ/ٱ → ا, ة → ه, ى → ي, ؤ → و,
   ئ → ي, diacritics and tatweel removed, Arabic-Indic digits → 0-9, lower case.
2. The **definite article** taken off every word that keeps at least three letters: ال، وال، بال، فال، كال، لل.
   A word is a run of letters and digits, so «(الشركة)» loses it like «الشركة».

`normalize_ar()` and `ArabicNormalizer` are not changed by step 2: it is a search layer, not normalisation.
The language of every field and every query is pinned to Arabic (`localizedAttributes`, `locales`); left to
itself the engine guesses the language of short Arabic text wrongly and stops finding words.

Highlighting maps the engine's match positions back to the original text and marks the whole original word,
article and diacritics included.

Known costs of removing the article: typo tolerance is weaker on short stems («الدهب» → «دهب» does not find
«ذهب»), and a few words collide («الماس» / «ماس»).

## Synonyms

Words that the form cannot bring together (broken plurals such as سعر/أسعار, spelling variants such as
شيكل/شيقل, different words for one thing) are synonyms that the admin manages on the synonyms screen. A word
belongs to one group only, compared in its search form. `synonyms:install` adds a starter list of economic
words.

## Experiment: the sound feminine plural (شركة / شركات)

Neither the normaliser nor the article layer brings «شركة» and «شركات» together. The experiment folds the
plural onto the singular in the search form: a word ending in «ات» with at least three letters before it ends
in «ه» instead («شركات» → «شركه», which is how «شركة» is normalised). It is behind `SEARCH_FEMININE_PLURAL`
(off). Turning it on changes the indexed text, so it needs `search:reindex` afterwards.

42 documents (the 32 of spike B plus 10 written for this experiment, with traps) and 53 queries, top 10.
Figures are recall / precision of the returned list / R-precision, in per cent. Indicative: one author wrote
both the documents and the queries.

| group | n | current | feminine | current + synonyms | feminine + synonyms |
|---|---|---|---|---|---|
| normaliser | 5 | 100 / 67 / 100 | 100 / 67 / 100 | 100 / 67 / 100 | 100 / 67 / 100 |
| article | 5 | 100 / 100 / 100 | 100 / 100 / 100 | 100 / 100 / 100 | 100 / 100 / 100 |
| broken plural | 10 | 50 / 69 / 47 | 50 / 69 / 47 | 94 / 73 / 79 | 94 / 73 / 79 |
| sound plural (spike) | 6 | 50 / 88 / 46 | 83 / 93 / 79 | 79 / 84 / 71 | 96 / 88 / 88 |
| typo | 3 | 67 / 50 / 67 | 67 / 50 / 67 | 67 / 50 / 67 | 67 / 50 / 67 |
| body only | 2 | 100 / 100 / 100 | 100 / 100 / 100 | 100 / 100 / 100 | 100 / 100 / 100 |
| two words | 2 | 75 / 50 / 75 | 75 / 50 / 75 | 100 / 31 / 100 | 100 / 31 / 100 |
| no result expected | 2 | 2/2 clean | 2/2 clean | 2/2 clean | 2/2 clean |
| article + plural | 4 | 73 / 65 / 64 | 73 / 65 / 64 | 93 / 71 / 80 | 93 / 71 / 80 |
| collision | 2 | 100 / 50 / 50 | 100 / 50 / 50 | 100 / 50 / 50 | 100 / 50 / 50 |
| feminine plural (new) | 7 | 64 / 69 / 64 | 100 / 81 / 100 | 64 / 69 / 64 | 100 / 81 / 100 |
| traps (new) | 5 | 100 / 67 / 100 | 100 / 63 / 80 | 100 / 67 / 100 | 100 / 63 / 80 |
| **all but the negatives** | 51 | **74 / 72 / 71** | **83 / 74 / 78** | **89 / 72 / 82** | **96 / 74 / 87** |

Per query, the plural pairs: «سيارات» 1/2 → 2/2, «الجامعات» 1/2 → 2/2, «مؤسسات» 1/2 → 2/2, «ساعة» 0/1 → 1/1,
«شركة» and «شركات» 1/2 → 2/2, «عملة» 1/3 → 3/3.

The cost shows in the traps: «حسابات» (accounts) now also finds «حسابه» (his account), because «ـه» the
pronoun and «ة» the feminine ending are the same letter after normalisation; the same holds for «عملات» and
«عمله» (his work). Words that end in «ات» in the singular («إثبات») still find themselves, since the query
is folded the same way.

**Reading:** the fold lifts recall on feminine plurals from about 64 % to 100 % at a small cost in precision
from pronoun collisions. With the starter synonyms already covering a few frequent pairs (شركة/شركات,
عملة/عملات), the fold still adds 7 points of recall overall.
