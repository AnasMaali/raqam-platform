<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Content\BodySanitizer;
use App\Models\MediaAsset;
use App\Models\Service;
use App\Support\UniqueSlug;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saves a service with what it includes and its own FAQ in one transaction (decisions 14.1–14.4, 14.7), after checking
 * what the database cannot: a price typed as money (see Money), in shekels or dollars, a special offer only under a set
 * price, cheaper than it and ending in the future, a public library picture, a cleaned description. The price change and
 * the list changes are in the audit log from → to.
 */
final class ServiceCatalog
{
    public const MAX_ITEMS = 20;

    public function __construct(private BodySanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PriceRejected
     */
    public function save(?Service $service, array $data, ?int $userId): Service
    {
        $clean = $this->check($service, $data);

        return DB::transaction(function () use ($service, $clean, $userId) {
            $before = $service ? ['deliverables' => $service->deliverables(), 'faqs' => self::faqLines($service->id)] : ['deliverables' => [], 'faqs' => []];

            $service ??= new Service(['sort_order' => (int) DB::table('services')->where('group_id', $clean['group_id'])->max('sort_order') + 1]);
            $service->fill(array_diff_key($clean, ['deliverables' => 1, 'faqs' => 1]));
            $service->slug = UniqueSlug::for('services', $clean['slug'] ?: $clean['name_ar'], $service->id, 'service');
            $service->save();

            DB::table('service_deliverables')->where('service_id', $service->id)->delete();
            foreach ($clean['deliverables'] as $i => $label) {
                DB::table('service_deliverables')->insert(['service_id' => $service->id, 'sort_order' => $i + 1, 'label' => $label]);
            }
            DB::table('faqs')->where('service_id', $service->id)->delete();
            foreach ($clean['faqs'] as $i => $faq) {
                DB::table('faqs')->insert(['service_id' => $service->id, 'sort_order' => $i + 1] + $faq);
            }

            $after = ['deliverables' => $service->deliverables(), 'faqs' => self::faqLines($service->id)];
            $changes = [];
            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = [implode("\n", $before[$field]) ?: null, implode("\n", $value) ?: null];
                }
            }
            if ($changes !== []) {
                AuditLogger::record('update', 'services', $service->id, $changes, $userId);
            }
            ServiceBoard::changed($service->slug);

            return $service->refresh();
        });
    }

    /** @return list<string> */
    private static function faqLines(int $serviceId): array
    {
        return DB::table('faqs')->where('service_id', $serviceId)->orderBy('sort_order')->get()
            ->map(fn ($f) => ($f->is_published ? '' : '[مخفي] ').$f->question.' — '.$f->answer)->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function check(?Service $service, array $data): array
    {
        $text = fn (string $key) => trim((string) ($data[$key] ?? ''));

        $name = $text('name_ar');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new PriceRejected('اسم الخدمة مطلوب، حتى 120 حرفاً.', 'name_ar');
        }
        $summary = $text('summary');
        if ($summary === '' || mb_strlen($summary) > 300) {
            throw new PriceRejected('الوصف القصير مطلوب، حتى 300 حرف.', 'summary');
        }
        $description = $text('description') === '' ? null : $this->sanitizer->clean($text('description'), false);
        if ($description !== null && mb_strlen($description) > 20000) {
            throw new PriceRejected('الوصف الكامل أطول من المسموح.', 'description');
        }

        $group = $data['group_id'] ?? null;
        if (! self::isId($group) || ! DB::table('service_groups')->where('id', (int) $group)->exists()) {
            throw new PriceRejected('اختر مجموعة الخدمة.', 'group_id');
        }
        $section = $data['section_id'] ?? null;
        if (filled($section) && (! self::isId($section) || ! DB::table('sections')->where('id', (int) $section)->exists())) {
            throw new PriceRejected('اختر قسماً موجوداً.', 'section_id');
        }
        $cover = $data['cover_media_id'] ?? null;
        if (filled($cover) && (! self::isId($cover) || ! MediaAsset::query()->publicImages()->whereKey((int) $cover)->exists())) {
            throw new PriceRejected('اختر صورة عامة موجودة في مكتبة الوسائط.', 'cover_media_id');
        }

        $deliverables = collect((array) ($data['deliverables'] ?? []))->map(fn ($d) => trim((string) (is_array($d) ? ($d['label'] ?? '') : $d)))->filter()->values();
        if ($deliverables->count() > self::MAX_ITEMS || $deliverables->contains(fn ($d) => mb_strlen($d) > 200)) {
            throw new PriceRejected('«ماذا تشمل» حتى '.self::MAX_ITEMS.' بنداً، كل بند حتى 200 حرف.', 'deliverables');
        }
        $faqs = collect((array) ($data['faqs'] ?? []))->map(fn ($f) => [
            'question' => trim((string) ($f['question'] ?? '')), 'answer' => trim((string) ($f['answer'] ?? '')), 'is_published' => (bool) ($f['is_published'] ?? true),
        ])->reject(fn ($f) => $f['question'] === '' && $f['answer'] === '')->values();
        if ($faqs->count() > self::MAX_ITEMS) {
            throw new PriceRejected('الأسئلة الشائعة حتى '.self::MAX_ITEMS.' سؤالاً.', 'faqs');
        }
        foreach ($faqs as $f) {
            if ($f['question'] === '' || $f['answer'] === '' || mb_strlen($f['question']) > 300 || mb_strlen($f['answer']) > 3000) {
                throw new PriceRejected('كل سؤال يحتاج نص السؤال (حتى 300 حرف) والجواب (حتى 3000 حرف).', 'faqs');
            }
        }

        return ['group_id' => (int) $group, 'section_id' => filled($section) ? (int) $section : null, 'name_ar' => $name, 'slug' => $text('slug'),
            'summary' => $summary, 'description' => $description, 'cover_media_id' => filled($cover) ? (int) $cover : null,
            'is_active' => (bool) ($data['is_active'] ?? false), 'deliverables' => $deliverables->all(), 'faqs' => $faqs->all()] + $this->price($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function price(array $data): array
    {
        $mode = (string) ($data['price_mode'] ?? '');
        if (! array_key_exists($mode, Service::MODES)) {
            throw new PriceRejected('اختر طريقة عرض السعر.', 'price_mode');
        }
        $none = ['price_mode' => $mode, 'price_amount' => null, 'currency_code' => null, 'price_note' => null, 'sale_price_amount' => null, 'sale_ends_at' => null];
        if ($mode === 'on_request') {
            return $none;
        }

        $currency = (string) ($data['currency_code'] ?? '');
        if (! array_key_exists($currency, Service::CURRENCIES)) {
            throw new PriceRejected('اختر العملة: شيكل أو دولار.', 'currency_code');
        }
        $decimals = (int) (DB::table('currencies')->where('code', $currency)->value('minor_units') ?? 2);
        $amount = self::minor((string) ($data['price'] ?? ''), $decimals, 'price');
        $note = trim((string) ($data['price_note'] ?? ''));
        if (mb_strlen($note) > 80) {
            throw new PriceRejected('ملاحظة السعر حتى 80 حرفاً (مثل «للحلقة»).', 'price_note');
        }

        $sale = null;
        $ends = null;
        if ((bool) ($data['offer'] ?? false)) {
            $sale = self::minor((string) ($data['sale_price'] ?? ''), $decimals, 'sale_price');
            if ($sale >= $amount) {
                throw new PriceRejected('سعر العرض يجب أن يكون أقل من السعر العادي ('.Money::fromMinor($amount, $decimals).').', 'sale_price');
            }
            try {
                $ends = filled($data['sale_ends_at'] ?? null) ? CarbonImmutable::parse((string) $data['sale_ends_at'], 'UTC')->utc() : null;
            } catch (\Throwable) {
                $ends = null;
            }
            if ($ends === null || $ends <= CarbonImmutable::now()) {
                throw new PriceRejected('اختر وقت انتهاء العرض في المستقبل؛ بعده يرجع السعر العادي وحده.', 'sale_ends_at');
            }
            if ($ends > CarbonImmutable::now()->addYear()) {
                throw new PriceRejected('العرض الخاص لسنة على الأكثر.', 'sale_ends_at');
            }
        }

        return ['price_mode' => $mode, 'price_amount' => $amount, 'currency_code' => $currency, 'price_note' => $note === '' ? null : $note,
            'sale_price_amount' => $sale, 'sale_ends_at' => $ends] + $none;
    }

    private static function minor(string $input, int $decimals, string $field): int
    {
        try {
            return Money::toMinor($input, $decimals);
        } catch (PriceRejected $e) {
            throw new PriceRejected($e->getMessage(), $field);
        }
    }

    private static function isId(mixed $value): bool
    {
        return is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) <= 9;
    }
}
