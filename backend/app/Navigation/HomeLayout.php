<?php

namespace App\Navigation;

use App\Audit\AuditLogger;
use App\Jobs\SendSiteWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The order of the home page (decision 21.11): blocks the admin drags into place and shows or hides — the main story,
 * breaking news, a category, a special section, video, awareness, currencies and weather, an ad slot, and a few more.
 * A block that needs a target (a category, a section, a playlist, an ad slot) has exactly one (the table's CHECKs say
 * the same). Saving tells the site at once (webhook) and goes to the audit trail.
 */
final class HomeLayout
{
    public const MAX_BLOCKS = 40;

    public const VERSION = 'home:version';

    /** @var array<string, string> type => Arabic name, in the order the picker offers them */
    public const TYPES = [
        'hero' => 'المادة الرئيسية',
        'breaking' => 'العاجل',
        'latest' => 'آخر الأخبار',
        'category' => 'قسم تصنيف',
        'section' => 'قسم خاص',
        'videos' => 'الفيديو (الأحدث)',
        'playlist' => 'قائمة تشغيل',
        'awareness' => 'التوعية',
        'most_read' => 'الأكثر قراءة',
        'markets' => 'العملات والطقس',
        'ad' => 'إعلان',
        'newsletter' => 'النشرة البريدية',
    ];

    /** The column that names each type's target. */
    public const TARGET = ['category' => 'category_id', 'section' => 'section_id', 'playlist' => 'playlist_id', 'ad' => 'placement_code'];

    /** Types that make sense once on a page. */
    public const ONCE = ['hero', 'breaking', 'latest', 'most_read', 'markets', 'newsletter', 'awareness', 'videos'];

    /** The ad slots a block may hold (the popup and the phone bar are not places on the page). */
    public const AD_SLOTS = ['header_leaderboard', 'between_sections', 'sidebar_mpu'];

    /** How many items a block shows when the admin leaves it empty. */
    public const DEFAULT_LIMIT = ['hero' => 5, 'breaking' => 5, 'latest' => 8, 'category' => 6, 'section' => 6, 'videos' => 8, 'playlist' => 8, 'awareness' => 6, 'most_read' => 5];

    /** @return list<array<string, mixed>> the blocks in order */
    public function load(): array
    {
        return DB::table('home_blocks')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'block_type' => $r->block_type, 'title_ar' => $r->title_ar,
            'category_id' => $r->category_id, 'section_id' => $r->section_id, 'playlist_id' => $r->playlist_id, 'placement_code' => $r->placement_code,
            'item_limit' => $r->item_limit, 'is_active' => (bool) $r->is_active,
        ])->all();
    }

    /** @return list<array<string, mixed>> a sensible first page: the fixed blocks, each main category, then every special section */
    public function defaults(): array
    {
        $block = fn (string $type, array $extra = []) => ['id' => null, 'block_type' => $type, 'title_ar' => null, 'category_id' => null, 'section_id' => null,
            'playlist_id' => null, 'placement_code' => null, 'item_limit' => null, 'is_active' => true, ...$extra];

        $blocks = [$block('hero'), $block('breaking'), $block('markets'), $block('latest'), $block('ad', ['placement_code' => 'between_sections'])];
        foreach (DB::table('categories')->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('id') as $id) {
            $blocks[] = $block('category', ['category_id' => (int) $id]);
        }
        $blocks[] = $block('most_read');
        $blocks[] = $block('videos');
        foreach (DB::table('sections')->orderBy('sort_order')->pluck('id', 'key') as $key => $id) {
            $blocks[] = $key === 'awareness' ? $block('awareness') : $block('section', ['section_id' => (int) $id]);
        }
        $blocks[] = $block('newsletter');

        return array_slice($blocks, 0, self::MAX_BLOCKS);
    }

    /** Writes the default page once, if there is none yet (home:install). Returns how many blocks were written. */
    public function install(): int
    {
        if (DB::table('home_blocks')->exists()) {
            return 0;
        }
        $this->save($this->defaults(), null);

        return DB::table('home_blocks')->count();
    }

    /**
     * Replaces the page with the given blocks, in this order. Blocks keep their id when they come back with it.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @throws ValidationException
     */
    public function save(array $blocks, ?int $userId): void
    {
        $blocks = $this->normalise($blocks);
        $this->validate($blocks);
        $before = $this->describe($this->load());

        try {
            DB::transaction(function () use ($blocks) {
                // The position is unique only at the end of the transaction (DEFERRABLE), so the blocks can swap places.
                $kept = [];
                foreach ($blocks as $position => $block) {
                    $values = ['block_type' => $block['block_type'], 'title_ar' => $block['title_ar'], 'category_id' => null, 'section_id' => null,
                        'playlist_id' => null, 'placement_code' => null, 'item_limit' => $block['item_limit'], 'sort_order' => $position + 1, 'is_active' => $block['is_active']];
                    if (isset(self::TARGET[$block['block_type']])) {
                        $values[self::TARGET[$block['block_type']]] = $block[self::TARGET[$block['block_type']]];
                    }
                    $id = $block['id'] !== null && DB::table('home_blocks')->where('id', $block['id'])->exists() ? $block['id'] : null;
                    if ($id === null) {
                        $id = DB::table('home_blocks')->insertGetId($values);
                    } else {
                        DB::table('home_blocks')->where('id', $id)->update($values);
                    }
                    $kept[] = $id;
                }
                DB::table('home_blocks')->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23503'], true)) {   // a CHECK or a target that vanished meanwhile
                throw ValidationException::withMessages(['blocks' => 'أحد البلوكات مرفوض، راجع الحقول.']);
            }
            throw $e;
        }

        $after = $this->describe($this->load());
        if ($before !== $after) {
            AuditLogger::record('settings', null, null, ['home.layout' => [$before, $after]], $userId);
            Cache::forever(self::VERSION, (int) Cache::get(self::VERSION, 0) + 1);
            SendSiteWebhook::notify(['type' => 'home', 'change' => 'layout', 'paths' => ['/']]);
        }
    }

    /** @return list<array<string, mixed>> the shape the rest of the class relies on, whatever came in */
    private function normalise(array $blocks): array
    {
        $int = fn ($v) => $v === null || $v === '' || ! is_numeric($v) ? null : (int) $v;

        return array_map(function ($b) use ($int) {
            $b = (array) $b;

            return [
                'id' => $int($b['id'] ?? null),
                'block_type' => is_string($b['block_type'] ?? null) ? $b['block_type'] : null,
                'title_ar' => ($t = trim((string) ($b['title_ar'] ?? ''))) === '' ? null : $t,
                'category_id' => $int($b['category_id'] ?? null),
                'section_id' => $int($b['section_id'] ?? null),
                'playlist_id' => $int($b['playlist_id'] ?? null),
                'placement_code' => ($p = trim((string) ($b['placement_code'] ?? ''))) === '' ? null : $p,
                'item_limit' => $int($b['item_limit'] ?? null),
                'is_active' => (bool) ($b['is_active'] ?? true),
            ];
        }, array_values($blocks));
    }

    /** @throws ValidationException */
    private function validate(array $blocks): void
    {
        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => 'عدد البلوكات كبير جداً (الحد '.self::MAX_BLOCKS.').']);
        }

        $errors = [];
        $seen = [];
        foreach ($blocks as $i => $block) {
            $validator = Validator::make($block, [
                'block_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
                'title_ar' => ['nullable', 'string', 'max:80'],
                'category_id' => ['required_if:block_type,category', 'nullable', 'integer', 'min:1', 'max:2147483647', 'exists:categories,id'],
                'section_id' => ['required_if:block_type,section', 'nullable', 'integer', 'min:1', 'max:32767', 'exists:sections,id'],
                'playlist_id' => ['required_if:block_type,playlist', 'nullable', 'integer', 'min:1', 'max:999999999999999999', 'exists:playlists,id'],
                'placement_code' => ['required_if:block_type,ad', 'nullable', 'in:'.implode(',', self::AD_SLOTS)],
                'item_limit' => ['nullable', 'integer', 'min:1', 'max:24'],
            ], [
                'block_type.required' => 'اختر نوع البلوك.',
                'block_type.in' => 'نوع البلوك غير معروف.',
                'title_ar.max' => 'العنوان أطول من 80 حرفاً.',
                'category_id.required_if' => 'اختر التصنيف.',
                'section_id.required_if' => 'اختر القسم.',
                'playlist_id.required_if' => 'اختر قائمة التشغيل.',
                'placement_code.required_if' => 'اختر مكان الإعلان.',
                'placement_code.in' => 'مكان الإعلان هذا لا يوضع في الصفحة.',
                'item_limit.*' => 'عدد العناصر من 1 إلى 24.',
                '*.exists' => 'الوجهة المختارة غير موجودة.',
                '*.max' => 'الوجهة المختارة غير موجودة.',
                '*.min' => 'الوجهة المختارة غير موجودة.',
            ]);
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors["{$i}.{$field}"] = $messages;
            }

            // The same category, section, playlist or slot twice, or a one-off block twice, is a mistake.
            $type = $block['block_type'];
            $key = isset(self::TARGET[$type]) ? $type.':'.$block[self::TARGET[$type]] : (in_array($type, self::ONCE, true) ? $type : null);
            if ($key !== null && isset($seen[$key])) {
                $errors["{$i}.block_type"][] = 'هذا البلوك موجود مرة أخرى في الصفحة.';
            }
            $seen[$key ?? "free:{$i}"] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** One line per block, for the audit trail. */
    private function describe(array $blocks): string
    {
        return implode("\n", array_map(fn (array $b) => (self::TYPES[$b['block_type']] ?? $b['block_type'])
            .(isset(self::TARGET[$b['block_type']]) ? ':'.$b[self::TARGET[$b['block_type']]] : '')
            .($b['title_ar'] !== null ? ' «'.$b['title_ar'].'»' : '').($b['item_limit'] !== null ? ' ×'.$b['item_limit'] : '')
            .($b['is_active'] ? '' : ' (مخفي)'), $blocks));
    }
}
