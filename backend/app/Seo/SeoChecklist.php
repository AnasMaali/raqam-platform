<?php

namespace App\Seo;

/**
 * The SEO assistant of the editor (decisions 23.7, 23.14, 23.15): reads what is on the form and says, in plain
 * words, what would help the page in search. Advice only; nothing here blocks a save.
 */
final class SeoChecklist
{
    /**
     * @param  array<string, mixed>  $state  title, excerpt, body, slug, tags, category_id, cover_media_id, seo[meta_title, meta_description]
     * @return list<array{ok: bool, text: string}>
     */
    public function evaluate(array $state): array
    {
        $seo = (array) ($state['seo'] ?? []);
        $title = trim((string) ($seo['meta_title'] ?? '')) ?: trim((string) ($state['title'] ?? ''));
        $description = trim((string) ($seo['meta_description'] ?? '')) ?: trim((string) ($state['excerpt'] ?? ''));
        $body = (string) ($state['body'] ?? '');
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('~<div[^>]*customBlock[^>]*></div>~', ' ', $body)))));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));

        $checks = [];
        $add = function (bool $ok, string $good, string $bad) use (&$checks) {
            $checks[] = ['ok' => $ok, 'text' => $ok ? $good : $bad];
        };

        $titleLength = mb_strlen($title);
        $add($titleLength >= 30 && $titleLength <= 60, "طول العنوان مناسب ({$titleLength} حرفاً).",
            $titleLength === 0 ? 'اكتب عنواناً.' : ($titleLength < 30 ? "العنوان قصير ({$titleLength} حرفاً)، الأفضل بين 30 و60." : "العنوان طويل ({$titleLength} حرفاً)، الأفضل بين 30 و60 كي لا يُقصّ في نتائج البحث."));

        $descriptionLength = mb_strlen($description);
        $add($descriptionLength >= 70 && $descriptionLength <= 160, "طول الوصف مناسب ({$descriptionLength} حرفاً).",
            $descriptionLength === 0 ? 'أضف ملخصاً أو وصفاً للصفحة (الأفضل بين 70 و160 حرفاً).' : ($descriptionLength < 70 ? "الوصف قصير ({$descriptionLength} حرفاً)، الأفضل بين 70 و160." : "الوصف طويل ({$descriptionLength} حرفاً)، الأفضل ألا يتجاوز 160."));

        $slug = (string) ($state['slug'] ?? '');
        $slugWords = $slug === '' ? 0 : count(array_filter(explode('-', $slug)));
        $add($slug !== '' && $slugWords <= 10 && mb_strlen($slug) <= 90, 'الرابط قصير ومقروء.', 'الرابط طويل، اختصره إلى أهم الكلمات.');

        $add(! empty($state['cover_media_id']), 'الصورة الرئيسية موجودة وبنص بديل.', 'اختر صورة رئيسية (إجبارية قبل النشر).');

        $add($words >= 120, "طول النص جيد ({$words} كلمة).", "النص قصير ({$words} كلمة)، المواد الأطول قليلاً تُفهرس أفضل.");

        $hasInternalLink = (bool) preg_match('~<a\s[^>]*href="/(?!/)~i', $body) || str_contains($body, 'data-id="read-also"');
        $add($hasInternalLink, 'فيه رابط داخلي إلى مادة أخرى.', 'لا يوجد رابط داخلي: أضف «اقرأ أيضاً» أو رابطاً إلى مادة ذات صلة.');

        if ($words > 300) {
            $add((bool) preg_match('~<h[23][ >]~i', $body), 'النص الطويل مقسّم بعناوين فرعية.', 'النص طويل: قسّمه بعناوين فرعية لتسهيل القراءة والفهرسة.');
        }

        $add(! empty($state['category_id']) || ! ($state['category_required'] ?? true), 'التصنيف محدد.', 'اختر تصنيفاً.');
        $add(! empty($state['tags']), 'فيه وسوم.', 'أضف وسماً أو اثنين.');

        return $checks;
    }
}
