# Arabic search spike: Meilisearch and the PostgreSQL fallback

Reproduce with a local Meilisearch (v1.54.3, bound to 127.0.0.1) and `php artisan search:evaluate`
(`backend/tests/Fixtures/search` holds the corpus and the labelled queries).

## How to read the numbers

- 32 short synthetic documents and 41 queries written by the author who also wrote the documents,
  so the figures are indicative, not statistical. The *differences between variants* are the useful part.
- A document is relevant when it contains the queried word in any inflection (title, excerpt or body).
- Variants (all sit on top of `ArabicNormalizer`, which is unchanged):
  - **auto-language**: default Meilisearch behaviour.
  - **base**: language pinned to Arabic (`localizedAttributes` + `locales`).
  - **strip_al**: base + the definite article (ال, وال, بال, كال, فال, لل) removed from words that keep 3+ letters, at index and query time.
  - **synonyms**: base + a small dictionary of singular/broken-plural pairs (5 pairs; the sound plurals and several other test words are deliberately absent).
  - **strip_al+synonyms**: both.
  - **pg_trgm**: `search_text` (title, subtitle, excerpt, normalised by the database) with `word_similarity` at the default 0.6 and at 0.4. It does not see the body.

## Results

Meilisearch 1.54.3 · 32 documents · 41 queries · top 10

### Per query (found / relevant, results returned)

| group | query | note | meili: auto-language | meili: base | meili: strip_al | meili: synonyms | meili: strip_al+synonyms | pg_trgm 0.6 | pg_trgm 0.4 |
|---|---|---|---|---|---|---|---|---|---|
| normalizer | إقتصاد فلسطين | همزة خاطئة + نص مشكّل | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (1) | 1/1 (2) |
| normalizer | انتاج الزيتون | أ/إ | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) |
| normalizer | مدرسه ريادة الاعمال | ة/ه | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) |
| normalizer | ٢٠٢٦ | أرقام هندية/لاتينية | 2/2 (2) | 2/2 (2) | 2/2 (2) | 2/2 (2) | 2/2 (2) | 2/2 (2) | 2/2 (2) |
| normalizer | 2026 موازنة | رقم لاتيني ضد هندي | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (1) | 1/1 (2) |
| al | الصابون | ال في الاستعلام | 2/3 (10) | 2/3 (10) | 3/3 (3) | 2/3 (10) | 3/3 (3) | 3/3 (3) | 3/3 (4) |
| al | صابون | بدون ال في الاستعلام | 2/3 (2) | 2/3 (2) | 3/3 (3) | 2/3 (2) | 3/3 (3) | 3/3 (3) | 3/3 (3) |
| al | الشيكل | ال في الاستعلام | 3/3 (10) | 3/3 (10) | 3/3 (3) | 3/3 (10) | 3/3 (3) | 2/3 (2) | 2/3 (7) |
| al | شيكل | بدون ال في الاستعلام | 3/3 (3) | 3/3 (3) | 3/3 (3) | 3/3 (3) | 3/3 (3) | 2/3 (2) | 2/3 (2) |
| al | للصابون | لل | 1/3 (1) | 1/3 (1) | 3/3 (3) | 1/3 (1) | 3/3 (3) | 2/3 (2) | 3/3 (3) |
| plural | سعر | مفرد ← أسعار (تكسير) | 2/6 (2) | 2/6 (2) | 2/6 (2) | 6/6 (6) | 6/6 (6) | 2/6 (2) | 2/6 (2) |
| plural | اسعار | أسعار ← سعر | 4/6 (4) | 4/6 (4) | 4/6 (4) | 6/6 (6) | 6/6 (6) | 3/6 (3) | 3/6 (3) |
| plural | مصنع | مفرد ← مصانع | 1/3 (1) | 1/3 (1) | 1/3 (1) | 3/3 (3) | 3/3 (3) | 1/3 (1) | 2/3 (2) |
| plural | مصانع | مصانع ← مصنع | 3/3 (3) | 3/3 (3) | 3/3 (3) | 3/3 (3) | 3/3 (3) | 2/3 (2) | 2/3 (2) |
| plural | تاجر | مفرد ← تجار | 1/3 (1) | 1/3 (1) | 1/3 (1) | 3/3 (3) | 3/3 (3) | 1/3 (1) | 1/3 (2) |
| plural | تجار | تجار ← تاجر | 2/3 (3) | 2/3 (3) | 2/3 (4) | 3/3 (4) | 3/3 (5) | 1/3 (2) | 1/3 (4) |
| plural | سوق | مفرد ← أسواق | 1/7 (1) | 1/7 (1) | 1/7 (1) | 7/7 (7) | 7/7 (7) | 1/7 (1) | 1/7 (1) |
| plural | اسواق | أسواق ← سوق | 6/7 (6) | 6/7 (6) | 6/7 (6) | 7/7 (7) | 7/7 (7) | 4/7 (4) | 4/7 (4) |
| plural | بنك | مفرد ← بنوك | 2/4 (2) | 2/4 (2) | 2/4 (2) | 4/4 (4) | 4/4 (4) | 1/4 (1) | 2/4 (2) |
| plural | بنوك | بنوك ← بنك | 2/4 (2) | 2/4 (2) | 2/4 (2) | 4/4 (4) | 4/4 (4) | 1/4 (1) | 2/4 (2) |
| sound | شركة | مفرد ← شركات (جمع مؤنث سالم) | 0/2 (0) | 1/2 (1) | 1/2 (1) | 1/2 (1) | 1/2 (1) | 2/2 (2) | 2/2 (7) |
| sound | شركات | شركات ← شركة | 1/2 (1) | 1/2 (1) | 1/2 (1) | 1/2 (1) | 1/2 (1) | 1/2 (1) | 1/2 (2) |
| sound | مشروع | مفرد ← مشاريع | 1/4 (1) | 1/4 (1) | 1/4 (1) | 1/4 (1) | 1/4 (1) | 1/4 (1) | 1/4 (1) |
| sound | مشاريع | مشاريع ← مشروع | 3/4 (3) | 3/4 (3) | 3/4 (3) | 3/4 (3) | 3/4 (3) | 2/4 (2) | 2/4 (2) |
| sound | عملة | مفرد ← عملات | 1/3 (1) | 1/3 (1) | 1/3 (1) | 1/3 (1) | 1/3 (1) | 1/3 (1) | 1/3 (1) |
| sound | عملات | عملات ← عملة | 2/3 (3) | 2/3 (3) | 2/3 (3) | 2/3 (3) | 2/3 (3) | 2/3 (2) | 3/3 (4) |
| typo | اقتصاذ فلسطين | خطأ حرف (ذ بدل د) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (2) |
| typo | الزيتوم | خطأ حرف في كلمة قصيرة نسبياً | 1/1 (10) | 1/1 (10) | 1/1 (2) | 1/1 (10) | 1/1 (2) | 1/1 (2) | 1/1 (2) |
| typo | الدهب | خطأ نقطة (د/ذ) | 2/2 (10) | 2/2 (10) | 0/2 (0) | 2/2 (10) | 0/2 (0) | 0/2 (0) | 1/2 (4) |
| body | خرسانة | كلمة في النص فقط | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 1/1 (1) | 0/1 (0) | 0/1 (0) |
| body | الخرسانه | كلمة في النص فقط + ال + ة/ه | 1/1 (10) | 1/1 (10) | 1/1 (1) | 1/1 (10) | 1/1 (1) | 0/1 (0) | 0/1 (0) |
| multi | سعر الذهب | كلمتان | 1/2 (2) | 1/2 (2) | 1/2 (2) | 2/2 (6) | 2/2 (6) | 2/2 (3) | 2/2 (3) |
| multi | صادرات الصابون | كلمتان | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (2) | 1/1 (4) |
| negative | كرة القدم | لا نتائج مرجوّة | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 | ✗ 1 |
| negative | فضاء | لا نتائج مرجوّة | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 | ✓ 0 |
| al+plural | الاسعار | ال + جمع تكسير | 5/6 (10) | 5/6 (10) | 4/6 (4) | 6/6 (10) | 6/6 (6) | 3/6 (3) | 3/6 (5) |
| al+plural | الاسواق | ال + جمع تكسير | 6/7 (10) | 6/7 (10) | 6/7 (6) | 7/7 (10) | 7/7 (7) | 3/7 (3) | 4/7 (4) |
| al+plural | المصانع | ال + جمع تكسير | 3/3 (10) | 3/3 (10) | 3/3 (3) | 3/3 (10) | 3/3 (3) | 2/3 (2) | 3/3 (6) |
| al+plural | للتجار | لل + جمع تكسير | 0/3 (0) | 0/3 (0) | 2/3 (4) | 0/3 (0) | 3/3 (5) | 0/3 (0) | 1/3 (2) |
| collision | ماس كهربائي | كلمة قصيرة، تتصادم مع «الألماس» بعد شيل ال | 1/1 (1) | 1/1 (1) | 1/1 (2) | 1/1 (1) | 1/1 (2) | 1/1 (1) | 1/1 (1) |
| collision | الماس | «الألماس» تتصادم مع «ماس» بعد شيل ال | 1/1 (10) | 1/1 (10) | 1/1 (2) | 1/1 (10) | 1/1 (2) | 1/1 (3) | 1/1 (10) |

### Per group (recall / precision of the returned list / R-precision = relevant among the first R results; negatives: queries answered with nothing)

| group | n | meili: auto-language | meili: base | meili: strip_al | meili: synonyms | meili: strip_al+synonyms | pg_trgm 0.6 | pg_trgm 0.4 |
|---|---|---|---|---|---|---|---|---|
| normalizer | 5 | 100 / 70 / 100 | 100 / 70 / 100 | 100 / 70 / 100 | 100 / 70 / 100 | 100 / 70 / 100 | 100 / 90 / 100 | 100 / 70 / 100 |
| al | 5 | 73 / 70 / 73 | 73 / 70 / 73 | 100 / 100 / 100 | 73 / 70 / 73 | 100 / 100 / 100 | 80 / 100 / 80 | 87 / 81 / 87 |
| plural | 10 | 53 / 97 / 53 | 53 / 97 / 53 | 53 / 95 / 53 | 100 / 98 / 97 | 100 / 96 / 97 | 37 / 95 / 37 | 45 / 88 / 45 |
| sound | 6 | 42 / 78 / 42 | 50 / 94 / 50 | 50 / 94 / 50 | 50 / 94 / 50 | 50 / 94 / 50 | 54 / 100 / 54 | 60 / 76 / 60 |
| typo | 3 | 100 / 43 / 83 | 100 / 43 / 83 | 67 / 50 / 67 | 100 / 43 / 83 | 67 / 50 / 67 | 67 / 50 / 33 | 83 / 42 / 50 |
| body | 2 | 100 / 55 / 100 | 100 / 55 / 100 | 100 / 100 / 100 | 100 / 55 / 100 | 100 / 100 / 100 | 0 / 0 / 0 | 0 / 0 / 0 |
| multi | 2 | 75 / 50 / 75 | 75 / 50 / 75 | 75 / 50 / 75 | 100 / 42 / 100 | 100 / 42 / 100 | 100 / 58 / 100 | 100 / 46 / 100 |
| negative | 2 | 2/2 clean | 2/2 clean | 2/2 clean | 2/2 clean | 2/2 clean | 2/2 clean | 1/2 clean |
| al+plural | 4 | 67 / 35 / 67 | 67 / 35 / 67 | 80 / 88 / 80 | 75 / 40 / 75 | 100 / 90 / 92 | 40 / 75 / 40 | 60 / 65 / 60 |
| collision | 2 | 100 / 55 / 50 | 100 / 55 / 50 | 100 / 50 / 50 | 100 / 55 / 50 | 100 / 50 / 50 | 100 / 67 / 100 | 100 / 55 / 100 |
| **all (without negatives)** | 39 | 71 / 70 / 67 | 72 / 72 / 68 | 74 / 84 / 72 | 86 / 73 / 82 | 90 / 84 / 85 | 60 / 82 / 58 | 68 / 68 / 65 |

## Findings

1. **Pin the language.** Meilisearch guesses the language of every text. On short Arabic strings the guess is
   unreliable and the same word is tokenised differently in the document and in the query, so it is not found
   (observed: a title containing the queried word was missing from the results). Setting `localizedAttributes`
   on the searchable fields and `locales: ["ara"]` on every search removed the problem. This is now in
   `PostDocument::settings()`.
2. **The normaliser does its job.** Hamza, ta marbuta, ya, diacritics, tatweel and Arabic-Indic digits: 100 % recall for Meilisearch and pg_trgm alike.
3. **Definite article.** Without handling, a query starting with ال pulls in up to 10 unrelated documents (Meilisearch
   splits the word into ال + stem and matches every word that starts with ال), and `لل`/`ال` forms are missed.
   Removing the article fixes recall and precision in that group. Costs measured: typo tolerance is lost on short
   stems (الدهب → دهب has 3 letters), and a few collisions (الماس / ماس).
4. **Broken plurals** (سعر ↔ أسعار): the normaliser plus Meilisearch finds about half. A synonyms dictionary reaches
   100 % on the listed words and changes nothing for unlisted ones, so it needs an owner and a growth path. Sound plurals (ة ↔ ات) are untouched by every variant.
5. **pg_trgm as a fallback.** Lower recall overall, no body search, and noise at 0.4; it is good enough as a safety net, not as the primary engine.

## Not done in this spike

- If the article is stripped, highlighting must map back to the original word (the current alignment handles
  diacritics, tatweel, hamza and whitespace only).
- No stemming of sound plurals; no cropping of the body for result snippets.
