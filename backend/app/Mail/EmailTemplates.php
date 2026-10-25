<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The editable texts of the system messages (20.7). A template's row is made from EmailCatalog the first time it is
 * needed (so a message never waits for an install command). Afterwards only install() changes it, and only while it
 * still holds a text the catalogue shipped (nobody edited it): then it gets the catalogue's text of now.
 */
final class EmailTemplates
{
    /** @return array{added: int, updated: int} rows made, and unedited rows brought up to the catalogue's text */
    public static function install(): array
    {
        $added = 0;
        foreach (array_keys(EmailCatalog::templates()) as $key) {
            $added += self::ensure($key) ? 1 : 0;
        }

        $updated = 0;
        foreach (DB::table('email_templates')->get(['key', 'subject', 'body_html', 'is_active']) as $row) {
            $shipped = EmailCatalogVersions::HASHES[$row->key] ?? [];
            $held = EmailCatalogVersions::hash($row->subject, $row->body_html, (bool) $row->is_active);
            if ($held === end($shipped) || ! in_array($held, $shipped, true)) {
                continue;
            }
            $t = EmailCatalog::get($row->key);
            // Only while the row still holds what was read: an edit saved meanwhile is kept.
            $updated += DB::table('email_templates')->where('key', $row->key)
                ->where('subject', $row->subject)->where('body_html', $row->body_html)->where('is_active', $row->is_active)
                ->update(['name_ar' => $t['name'], 'audience' => $t['audience'], 'subject' => $t['subject'], 'body_html' => $t['body'],
                    'is_active' => $t['active'] ?? true, 'updated_at' => now()]);
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /** Whether the row was added just now. */
    public static function ensure(string $key): bool
    {
        $t = EmailCatalog::get($key);

        return DB::table('email_templates')->insertOrIgnore([
            'key' => $key, 'name_ar' => $t['name'], 'audience' => $t['audience'], 'subject' => $t['subject'],
            'body_html' => $t['body'], 'is_active' => $t['active'] ?? true,
        ]) === 1;
    }

    /** @return object{key: string, subject: string, body_html: string, is_active: bool} */
    public static function find(string $key): object
    {
        self::ensure($key);

        return DB::table('email_templates')->where('key', $key)->first(['key', 'subject', 'body_html', 'is_active']);
    }

    public static function isActive(string $key): bool
    {
        $active = (bool) self::find($key)->is_active;   // find() makes the row, which email_log refers to

        return $active || EmailCatalog::isLocked($key);
    }

    /**
     * @throws ValidationException with the field (subject, body_html, is_active) and an Arabic reason
     */
    public static function save(string $key, string $subject, string $body, bool $active, ?int $userId): EmailTemplate
    {
        self::ensure($key);
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        try {
            $body = EmailBodySanitizer::clean($body);
        } catch (\LengthException $e) {
            throw ValidationException::withMessages(['body_html' => $e->getMessage()]);
        }

        $errors = [];
        if ($subject === '' || mb_strlen($subject) > 200) {
            $errors['subject'] = 'العنوان مطلوب، وحتى 200 حرف.';
        }
        if (trim(strip_tags($body)) === '') {
            $errors['body_html'] = 'نص الرسالة مطلوب.';
        }
        foreach (['subject' => $subject, 'body_html' => $body] as $field => $text) {
            if ($unknown = self::unknownVariables($key, $text)) {
                $errors[$field] ??= 'متغير غير معروف: {'.implode('}، {', $unknown).'}. المتاح: {'.implode('}، {', array_keys(EmailCatalog::variables($key))).'}.';
            }
        }
        foreach (EmailCatalog::required($key) as $name) {
            if (! str_contains($body, '{'.$name.'}')) {
                $errors['body_html'] ??= 'المتغير {'.$name.'} إجباري في نص هذه الرسالة؛ بدونه لا تؤدي الرسالة عملها.';
            }
        }
        if (! $active && EmailCatalog::isLocked($key)) {
            $errors['is_active'] = 'هذه الرسالة لا يمكن إيقافها.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $template = EmailTemplate::query()->findOrFail($key);
        $template->fill(['subject' => $subject, 'body_html' => $body, 'is_active' => $active, 'updated_by' => $userId])->save();

        return $template;
    }

    /** @return list<string> names written {like this} that the template does not offer */
    public static function unknownVariables(string $key, string $text): array
    {
        preg_match_all('/\{([^{}\s<>]{1,40})\}/u', $text, $m);

        return array_values(array_unique(array_diff($m[1], array_keys(EmailCatalog::variables($key)))));
    }
}
