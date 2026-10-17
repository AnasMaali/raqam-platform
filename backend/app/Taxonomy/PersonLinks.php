<?php

namespace App\Taxonomy;

use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The social links of an author or a guest: one address per platform, kept in author_links / guest_links.
 * Saved with the person; each change is written to the audit trail under the person's own record.
 */
final class PersonLinks
{
    private const TABLES = ['authors' => ['author_links', 'author_id'], 'guests' => ['guest_links', 'guest_id']];

    /** @return array<string, string> platform code => name, in display order */
    public function platforms(): array
    {
        return DB::table('social_platforms')->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    /** @return array<string, string> platform code => url */
    public function load(string $owner, int $id): array
    {
        [$table, $column] = self::TABLES[$owner];

        return DB::table($table)->where($column, $id)->pluck('url', 'platform_code')->all();
    }

    /**
     * @param  array<string, mixed>  $links  platform code => url (empty removes the link)
     *
     * @throws ValidationException
     */
    public function sync(string $owner, int $id, array $links): void
    {
        [$table, $column] = self::TABLES[$owner];
        $platforms = array_keys($this->platforms());

        $wanted = [];
        foreach ($platforms as $code) {
            if (array_key_exists($code, $links)) {
                $url = trim((string) $links[$code]);
                $wanted[$code] = $url === '' ? null : $url;
            }
        }

        Validator::make($wanted, array_fill_keys(array_keys($wanted), ['nullable', 'url:http,https', 'max:2048']), [
            '*.url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://',
        ])->validate();

        DB::transaction(function () use ($owner, $id, $table, $column, $wanted) {
            $current = $this->load($owner, $id);
            $changes = [];

            foreach ($wanted as $code => $url) {
                $old = $current[$code] ?? null;
                if ($old === $url) {
                    continue;
                }

                if ($url === null) {
                    DB::table($table)->where($column, $id)->where('platform_code', $code)->delete();
                } else {
                    DB::table($table)->upsert([[$column => $id, 'platform_code' => $code, 'url' => $url]], [$column, 'platform_code'], ['url']);
                }
                $changes["link.{$code}"] = [$old, $url];
            }

            if ($changes !== []) {
                AuditLogger::record('update', $owner, $id, $changes);
            }
        });
    }
}
