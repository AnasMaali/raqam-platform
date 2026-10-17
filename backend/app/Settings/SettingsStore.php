<?php

namespace App\Settings;

use App\Models\Setting;
use App\Models\SiteSocialLink;
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
            $rules[$this->fieldName($key)] = SettingsCatalog::rules($field['type']);
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

        Validator::make($input, $rules, $messages)->validate();

        try {
            DB::transaction(function () use ($values, $social, $userId) {
                foreach ($values as $key => $value) {
                    $this->put($key, $value, $userId);
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

    private function put(string $key, ?string $value, ?int $userId): void
    {
        $setting = Setting::query()->find($key);

        if ($setting === null) {
            Setting::query()->create([
                'key' => $key,
                'value' => $value,
                'value_type' => SettingsCatalog::fields()[$key]['type'],
                'updated_by' => $userId,
            ]);

            return;
        }

        if ($setting->value !== $value) {
            $setting->update(['value' => $value, 'updated_by' => $userId]);
        }
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
