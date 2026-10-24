<?php

namespace App\Settings;

use App\Models\MediaAsset;
use App\Models\Setting;
use App\Models\SiteSocialLink;
use App\Services\ServiceBoard;
use App\Services\ServiceContact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reads and saves the general settings (21.13). Every value is checked against its type here and then
 * again by the table's own CHECKs; a change is audited by the models (action "settings").
 */
final class SettingsStore
{
    /** @return array<string, string|null> key => value, for every key of the catalog */
    public function values(): array
    {
        $stored = Setting::query()->whereIn('key', SettingsCatalog::keys())->pluck('value', 'key');

        return collect(SettingsCatalog::keys())->mapWithKeys(fn ($key) => [$key => $stored[$key] ?? null])->all();
    }

    /** @return array<string, string> platform code => url */
    public function socialLinks(): array
    {
        return SiteSocialLink::query()->pluck('url', 'platform_code')->all();
    }

    /** @return array<string, string> platform code => name, in display order */
    public function socialPlatforms(): array
    {
        return DB::table('social_platforms')->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    /**
     * @param  array<string, mixed>  $values  key => value
     * @param  array<string, mixed>  $social  platform code => url (empty removes the link)
     *
     * @throws ValidationException
     */
    public function save(array $values, array $social, ?int $userId): void
    {
        $values = $this->clean($values, SettingsCatalog::keys());
        $social = $this->clean($social, array_keys($this->socialPlatforms()));

        $rules = [];
        foreach (SettingsCatalog::fields() as $key => $field) {
            $rules[$this->fieldName($key)] = [...SettingsCatalog::rules($field['type']), ...(isset($field['options']) ? ['in:'.implode(',', array_keys($field['options']))] : [])];
        }
        foreach (array_keys($social) as $code) {
            $rules["social.{$code}"] = ['nullable', 'url:http,https', 'max:2048'];
        }

        $input = [];
        foreach ($values as $key => $value) {
            $input[$this->fieldName($key)] = $value;
        }
        $input['social'] = $social;

        $messages = [];
        foreach (SettingsCatalog::keys() as $key) {
            $messages += SettingsCatalog::messages($this->fieldName($key));
        }

        Validator::make($input, $rules, $messages)->after(function ($validator) use ($values) {
            // A "media" setting must name a public image that is in the library and not in the trash.
            foreach (SettingsCatalog::fields() as $key => $field) {
                $value = $values[$key] ?? null;
                if ($field['type'] === 'media' && $value !== null && ! MediaAsset::query()->publicImages()->whereKey((int) $value)->exists()) {
                    $validator->errors()->add($this->fieldName($key), 'اختر صورة عامة موجودة في مكتبة الوسائط.');
                }
            }
        })->validate();

        try {
            DB::transaction(function () use ($values, $social, $userId) {
                $changed = [];
                foreach ($values as $key => $value) {
                    if ($this->put($key, $value, $userId)) {
                        $changed[] = $key;
                    }
                }
                // The contact buttons of every service and of the advertise page are made of these (14.8).
                if (array_intersect($changed, ServiceContact::SETTINGS) !== []) {
                    ServiceBoard::changed(null, everyPage: true);
                }
                foreach ($social as $code => $url) {
                    $this->putSocial($code, $url);
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw ValidationException::withMessages(['settings' => 'إحدى القيم مرفوضة، راجع الحقول وحاول مجدداً.']);
            }
            throw $e;
        }
    }

    /** The name a key has in a form: dots are not allowed in a Livewire property path. */
    public function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return bool whether the value changed */
    private function put(string $key, ?string $value, ?int $userId): bool
    {
        $setting = Setting::query()->find($key);

        if ($setting === null) {
            // Nothing to keep: an empty value of a setting never stored is not a change (and no empty entry in the audit trail).
            if ($value === null) {
                return false;
            }
            Setting::query()->create([
                'key' => $key,
                'value' => $value,
                'value_type' => SettingsCatalog::fields()[$key]['type'],
                'updated_by' => $userId,
            ]);

            return true;
        }

        if ($setting->value !== $value) {
            $setting->update(['value' => $value, 'updated_by' => $userId]);

            return true;
        }

        return false;
    }

    private function putSocial(string $code, ?string $url): void
    {
        $link = SiteSocialLink::query()->find($code);

        if ($url === null) {
            $link?->delete();
        } elseif ($link === null) {
            $order = (int) DB::table('social_platforms')->where('code', $code)->value('sort_order');
            SiteSocialLink::query()->create(['platform_code' => $code, 'url' => $url, 'sort_order' => $order]);
        } elseif ($link->url !== $url) {
            $link->update(['url' => $url]);
        }
    }

    /**
     * Keeps only the known keys that were actually sent (the others are left alone) and turns blank input into "no value".
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     * @return array<string, string|null>
     */
    private function clean(array $input, array $allowed): array
    {
        $clean = [];
        foreach ($allowed as $key) {
            $name = array_key_exists($key, $input) ? $key : $this->fieldName($key);
            if (! array_key_exists($name, $input)) {
                continue;
            }
            $value = $input[$name];
            $value = is_string($value) ? trim($value) : $value;
            $clean[$key] = ($value === '' || $value === null) ? null : (string) $value;
        }

        return $clean;
    }
}
