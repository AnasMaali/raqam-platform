<?php

namespace App\Seo;

use App\Models\MediaAsset;
use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves the "SEO" part of a form (decisions 23.7, 23.8). The owner row keeps a seo_meta_id; when every
 * field is left empty there is no seo_meta row at all, and the site falls back to the automatic values.
 */
final class SeoMetaSaver
{
    public const FIELDS = ['meta_title', 'meta_description', 'canonical_url', 'robots_noindex', 'share_media_id'];

    public const TITLE_MAX = 70;

    public const DESCRIPTION_MAX = 170;

    /** @return array<string, mixed> the owner's current values, for a form to fill */
    public function load(Model $owner): array
    {
        return $owner->seoMeta?->only(self::FIELDS) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return bool whether the owner's SEO values changed
     *
     * @throws ValidationException
     */
    public function sync(Model $owner, array $data): bool
    {
        $values = $this->clean($data);
        $this->validate($values);

        $changed = DB::transaction(function () use ($owner, $values) {
            $meta = $owner->seoMeta;

            if ($this->isEmpty($values)) {
                if ($meta === null) {
                    return false;
                }
                $owner->forceFill(['seo_meta_id' => null])->save();
                $meta->delete();

                return true;
            }

            if ($meta === null) {
                $meta = SeoMeta::query()->create($values);
                $owner->forceFill(['seo_meta_id' => $meta->id])->save();

                return true;
            }

            $meta->fill($values);
            $dirty = $meta->isDirty();
            $meta->save();

            return $dirty;
        });

        $owner->unsetRelation('seoMeta');

        return $changed;
    }

    /** @return array<string, mixed> */
    private function clean(array $data): array
    {
        $text = fn ($key) => ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : $v;

        return [
            'meta_title' => $text('meta_title'),
            'meta_description' => $text('meta_description'),
            'canonical_url' => $text('canonical_url'),
            'robots_noindex' => (bool) ($data['robots_noindex'] ?? false),
            'share_media_id' => ($data['share_media_id'] ?? null) === null || $data['share_media_id'] === '' ? null : (int) $data['share_media_id'],
        ];
    }

    private function isEmpty(array $values): bool
    {
        return $values['meta_title'] === null && $values['meta_description'] === null && $values['canonical_url'] === null
            && $values['robots_noindex'] === false && $values['share_media_id'] === null;
    }

    /** @throws ValidationException */
    public function check(array $data): void
    {
        $this->validate($this->clean($data));
    }

    private function validate(array $values): void
    {
        Validator::make($values, [
            'meta_title' => ['nullable', 'string', 'max:'.self::TITLE_MAX],
            'meta_description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'canonical_url' => ['nullable', 'url:http,https', 'max:2048'],
            'share_media_id' => ['nullable', 'integer'],
        ], [
            'meta_title.max' => 'العنوان أطول من '.self::TITLE_MAX.' حرفاً.',
            'meta_description.max' => 'الوصف أطول من '.self::DESCRIPTION_MAX.' حرفاً.',
            'canonical_url.url' => 'الرابط الأساسي غير صحيح، ويجب أن يبدأ بـ https://',
        ])->after(function ($validator) use ($values) {
            if ($values['share_media_id'] !== null && ! MediaAsset::query()->publicImages()->whereKey($values['share_media_id'])->exists()) {
                $validator->errors()->add('share_media_id', 'اختر صورة عامة موجودة في مكتبة الوسائط.');
            }
        })->validate();
    }
}
