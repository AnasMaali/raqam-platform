<?php

namespace App\Navigation;

use App\Audit\AuditLogger;
use App\Support\SafeUrl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The header and footer menus (decision 21.12): a list of links, each with at most one level of children,
 * in the order the admin drags them. Every link points at exactly one thing: a page, a category, a section
 * or an address (the table's CHECK says the same).
 */
final class MenuStore
{
    public const MAX_ITEMS = 60;

    public const TYPES = ['page', 'category', 'section', 'url'];

    private const TARGET_COLUMN = ['page' => 'page_id', 'category' => 'category_id', 'section' => 'section_id', 'url' => 'url'];

    /** @return array<string, string> key => Arabic name */
    public function menus(): array
    {
        return DB::table('menus')->orderBy('id')->pluck('name_ar', 'key')->all();
    }

    /**
     * @return list<array<string, mixed>> top-level items in order, each with its 'children'
     */
    public function load(string $key): array
    {
        $menuId = $this->menuId($key);
        $rows = DB::table('menu_items')->where('menu_id', $menuId)->orderBy('sort_order')->orderBy('id')->get();

        $item = function ($row) {
            $type = collect(self::TARGET_COLUMN)->filter(fn ($column) => $row->{$column} !== null)->keys()->first();

            return [
                'id' => $row->id, 'label_ar' => $row->label_ar, 'type' => $type,
                'page_id' => $row->page_id, 'category_id' => $row->category_id, 'section_id' => $row->section_id, 'url' => $row->url,
                'opens_new_tab' => (bool) $row->opens_new_tab, 'is_active' => (bool) $row->is_active, 'children' => [],
            ];
        };

        $tree = [];
        foreach ($rows->whereNull('parent_id') as $row) {
            $tree[$row->id] = $item($row);
        }
        foreach ($rows->whereNotNull('parent_id') as $row) {
            if (isset($tree[$row->parent_id])) {
                $tree[$row->parent_id]['children'][] = $item($row);
            }
        }

        return array_values($tree);
    }

    /**
     * Replaces the menu with the given tree. Items keep their id when they come back with it.
     *
     * @param  list<array<string, mixed>>  $tree
     *
     * @throws ValidationException
     */
    public function save(string $key, array $tree, ?int $userId = null): void
    {
        $menuId = $this->menuId($key);
        $tree = $this->normalise($tree);
        $this->validate($tree);

        $before = $this->describe($this->load($key));

        try {
            DB::transaction(function () use ($menuId, $tree) {
                $kept = [];
                foreach ($tree as $position => $item) {
                    $parentId = $this->put($menuId, null, $position, $item);
                    $kept[] = $parentId;
                    foreach ($item['children'] as $childPosition => $child) {
                        $kept[] = $this->put($menuId, $parentId, $childPosition, $child);
                    }
                }

                // Children go with their parent (ON DELETE CASCADE); anything not listed is gone.
                DB::table('menu_items')->where('menu_id', $menuId)->whereNotIn('id', $kept)->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {   // a CHECK we did not predict
                throw ValidationException::withMessages(['menu' => 'أحد الروابط مرفوض، راجع الحقول.']);
            }
            throw $e;
        }

        $after = $this->describe($this->load($key));
        if ($before !== $after) {
            AuditLogger::record('update', 'menus', $menuId, ['items' => [$before, $after]], $userId);
        }
    }

    private function menuId(string $key): int
    {
        return (int) (DB::table('menus')->where('key', $key)->value('id') ?? throw new \InvalidArgumentException("Unknown menu {$key}"));
    }

    /** One item inserted or updated; returns its id. */
    private function put(int $menuId, ?int $parentId, int $position, array $item): int
    {
        $values = [
            'menu_id' => $menuId, 'parent_id' => $parentId, 'label_ar' => $item['label_ar'],
            'page_id' => null, 'category_id' => null, 'section_id' => null, 'url' => null,
            'opens_new_tab' => $item['opens_new_tab'], 'is_active' => $item['is_active'], 'sort_order' => $position,
        ];
        $values[self::TARGET_COLUMN[$item['type']]] = $item[self::TARGET_COLUMN[$item['type']]];

        // An id that belongs to another menu is not ours to update: it becomes a new item.
        $id = $item['id'] !== null && DB::table('menu_items')->where('id', $item['id'])->where('menu_id', $menuId)->exists() ? $item['id'] : null;

        if ($id === null) {
            return DB::table('menu_items')->insertGetId($values);
        }

        DB::table('menu_items')->where('id', $id)->update($values);

        return $id;
    }

    /** @return list<array<string, mixed>> the shape the rest of the class relies on, whatever came in */
    private function normalise(array $tree): array
    {
        $clean = function ($item, bool $withChildren) use (&$clean) {
            $item = (array) $item;
            $type = $item['type'] ?? null;
            $out = [
                'id' => isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null,
                'label_ar' => trim((string) ($item['label_ar'] ?? '')),
                'type' => in_array($type, self::TYPES, true) ? $type : null,
                'page_id' => $this->intOrNull($item['page_id'] ?? null),
                'category_id' => $this->intOrNull($item['category_id'] ?? null),
                'section_id' => $this->intOrNull($item['section_id'] ?? null),
                'url' => ($u = trim((string) ($item['url'] ?? ''))) === '' ? null : $u,
                'opens_new_tab' => (bool) ($item['opens_new_tab'] ?? false),
                'is_active' => (bool) ($item['is_active'] ?? true),
                'children' => [],
            ];
            if ($withChildren) {
                foreach (array_values((array) ($item['children'] ?? [])) as $child) {
                    $out['children'][] = $clean($child, false);
                }
            }

            return $out;
        };

        return array_map(fn ($item) => $clean($item, true), array_values($tree));
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @throws ValidationException */
    private function validate(array $tree): void
    {
        $flat = [];
        foreach ($tree as $i => $item) {
            $flat["{$i}"] = $item;
            foreach ($item['children'] as $j => $child) {
                $flat["{$i}.children.{$j}"] = $child;
            }
        }

        if (count($flat) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['menu' => 'عدد الروابط كبير جداً (الحد '.self::MAX_ITEMS.').']);
        }

        $errors = [];
        foreach ($flat as $path => $item) {
            $validator = Validator::make($item, [
                'label_ar' => ['required', 'string', 'max:60'],
                'type' => ['required', 'in:'.implode(',', self::TYPES)],
                'page_id' => ['required_if:type,page', 'nullable', 'integer', 'min:1', 'max:2147483647', 'exists:pages,id'],
                'category_id' => ['required_if:type,category', 'nullable', 'integer', 'min:1', 'max:2147483647', 'exists:categories,id'],
                'section_id' => ['required_if:type,section', 'nullable', 'integer', 'min:1', 'max:32767', 'exists:sections,id'],   // smallint in the table
                'url' => ['required_if:type,url', 'nullable', 'max:500', 'regex:'.SafeUrl::PATTERN],
            ], [
                'label_ar.required' => 'اكتب اسم الرابط.',
                'label_ar.max' => 'الاسم أطول من 60 حرفاً.',
                'type.required' => 'اختر وجهة الرابط.',
                'page_id.required_if' => 'اختر الصفحة.',
                'category_id.required_if' => 'اختر التصنيف.',
                'section_id.required_if' => 'اختر القسم.',
                'url.required_if' => 'اكتب العنوان.',
                'url.regex' => 'العنوان يجب أن يبدأ بـ / لصفحة من الموقع أو بـ https:// لموقع آخر.',
                '*.exists' => 'الوجهة المختارة غير موجودة.',
                '*.max' => 'الوجهة المختارة غير موجودة.',
                '*.min' => 'الوجهة المختارة غير موجودة.',
            ]);

            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors["{$path}.{$field}"] = $messages;
            }

            // Only the chosen destination is kept: a leftover value in another field is not an error, it is ignored.
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** One line per link, for the audit trail. */
    private function describe(array $tree): string
    {
        $line = fn (array $i, string $indent) => $indent.$i['label_ar'].' → '.$i['type'].':'.($i[self::TARGET_COLUMN[$i['type']]] ?? '').($i['is_active'] ? '' : ' (مخفي)');
        $lines = [];
        foreach ($tree as $item) {
            $lines[] = $line($item, '');
            foreach ($item['children'] as $child) {
                $lines[] = $line($child, '  ');
            }
        }

        return implode("\n", $lines);
    }
}
