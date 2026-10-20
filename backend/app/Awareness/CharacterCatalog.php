<?php

namespace App\Awareness;

use App\Models\Character;

/**
 * The eight characters of decision 7.1 – 7.8 with their first names, roles and colours. They are starting
 * data: the admin renames or recolours any of them afterwards, and `characters:install` never overwrites one.
 */
final class CharacterCatalog
{
    /** @var list<array{slug: string, name: string, role: string, color: string}> */
    public const DEFAULTS = [
        ['slug' => 'raqam', 'name' => 'رَقَم', 'role' => 'الراوي: يقدّم البطاقات ويحكي القصة', 'color' => '#0B5FA5'],
        ['slug' => 'did-you-know', 'name' => 'هل تعلم؟', 'role' => 'يشارك معلومة اقتصادية مفاجئة', 'color' => '#8A5A00'],
        ['slug' => 'watch-out', 'name' => 'انتبه!', 'role' => 'ينبّه إلى خطر أو خطأ شائع', 'color' => '#B3261E'],
        ['slug' => 'advisor', 'name' => 'المستشار', 'role' => 'يعطي نصيحة عملية للأفراد وأصحاب الأعمال', 'color' => '#1F6F5B'],
        ['slug' => 'saver', 'name' => 'المدّخِر', 'role' => 'يشرح طرق الادخار وإدارة المال', 'color' => '#0F766E'],
        ['slug' => 'rights-guard', 'name' => 'حارس الحقوق', 'role' => 'يعرّف بحقوق المستهلك والعامل', 'color' => '#5B3FA0'],
        ['slug' => 'fraud-spotter', 'name' => 'كاشف الاحتيال', 'role' => 'يكشف أساليب الاحتيال والنصب', 'color' => '#A1370F'],
        ['slug' => 'number-of-the-day', 'name' => 'رقم اليوم', 'role' => 'يعرض رقماً اقتصادياً ويشرحه', 'color' => '#1E3A8A'],
    ];

    /** Adds the characters that are missing (by address); the ones already there are left exactly as they are. */
    public function install(): int
    {
        $added = 0;

        foreach (self::DEFAULTS as $i => $default) {
            if (Character::query()->where('slug', $default['slug'])->exists() || Character::query()->where('name_ar', $default['name'])->exists()) {
                continue;
            }

            Character::query()->create([
                'slug' => $default['slug'], 'name_ar' => $default['name'], 'role_ar' => $default['role'],
                'accent_color' => $default['color'], 'sort_order' => ($i + 1) * 10, 'is_active' => true,
            ]);
            $added++;
        }

        return $added;
    }
}
