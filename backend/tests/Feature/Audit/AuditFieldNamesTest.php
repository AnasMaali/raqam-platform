<?php

use App\Audit\Audited;
use App\Audit\AuditFieldNames;
use App\Content\Subtypes\SubtypeRegistry;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Mail\EmailCatalog;
use App\Models\AuditLog;
use App\Models\User;
use App\Settings\SettingsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * The audit screen names each field in Arabic (decision 2026-11-14). Every field the trail can record has a name in
 * App\Audit\AuditFieldNames: a column added to an audited table without one fails here. The values before and after
 * stay as they are stored.
 */

/** @return array<string, string> field => where it comes from */
function auditedFields(): array
{
    $fields = [];
    $columns = fn (string $table) => DB::table('information_schema.columns')->where('table_schema', 'public')
        ->where('table_name', $table)->pluck('column_name')->all();

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! in_array(Audited::class, class_uses_recursive($class), true)) {
            continue;
        }
        $model = new $class;
        $ignored = (new ReflectionMethod($model, 'auditIgnored'))->invoke($model);
        foreach (array_diff($columns($model->getTable()), $ignored) as $column) {
            $fields[$column] ??= $model->getTable();
        }
    }

    // A post's kind writes its own facts as kind.column.
    foreach (app(SubtypeRegistry::class)->all() as $subtype) {
        foreach (array_diff($columns($subtype->table()), ['post_id', 'kind_code']) as $column) {
            $fields[$subtype->kind().'.'.$column] = $subtype->table();
        }
    }

    // The fields the code that writes the trail names itself: the keys inside each AuditLogger::record(...) call and
    // each $changes['…'] it builds, in every file that writes the trail.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if (! str_contains($code, 'AuditLogger::record') && ! str_contains($code, 'function auditChanges')) {
            continue;
        }
        $where = str_replace(app_path().'/', '', $file->getPathname());
        preg_match_all('/AuditLogger::record\((.*?)\);/s', $code, $calls);
        foreach ($calls[1] as $call) {
            preg_match_all("/'([a-z_]+(?:\\.[a-z_]+)?)' => \\[/", $call, $keys);
            foreach ($keys[1] as $key) {
                $fields[$key] ??= $where;
            }
        }
        preg_match_all("/\\\$changes\\['([a-z_.]+)'\\]\\s*=/", $code, $keys);
        preg_match_all('/function auditChanges.*?\\n    \\}/s', $code, $methods);
        foreach ($methods[0] as $method) {
            preg_match_all("/\\['([a-z_]+)' => \\[/", $method, $more);
            $keys[1] = [...$keys[1], ...$more[1]];
        }
        foreach ($keys[1] as $key) {
            $fields[$key] ??= $where;
        }
    }

    // Named through a variable: the post's lists (PostSaver::replace), a booking's column (BookingInbox::change), a
    // missing page set right or opened again (MissingPages), a campaign's design per device (AdCampaigns).
    foreach (['tags', 'authors', 'guests', 'gallery', 'scheduled_for', 'published_post_id', 'admin_notes', 'resolved_path', 'reopened_path',
        'creative_desktop', 'creative_mobile'] as $key) {
        $fields[$key] ??= 'named through a variable';
    }

    return $fields;
}

test('every field the audit trail records has an Arabic name', function () {
    $fields = auditedFields();
    $nameless = array_filter($fields, fn ($from, $field) => AuditFieldNames::label($field) === null, ARRAY_FILTER_USE_BOTH);

    expect(count($fields))->toBeGreaterThan(150)
        ->and($nameless)->toBe([], 'fields without an Arabic name in App\Audit\AuditFieldNames');
});

test('a field the trail names itself is named from where its name lives', function () {
    $nameless = [];
    // A setting by its key (the settings that exist and the ones the settings screen shows).
    foreach ([...DB::table('settings')->pluck('key')->all(), ...array_keys(SettingsCatalog::fields())] as $key) {
        AuditFieldNames::label($key) ?? $nameless[] = $key;
    }
    foreach (DB::table('feature_flags')->pluck('key') as $key) {
        AuditFieldNames::label("feature.{$key}") ?? $nameless[] = "feature.{$key}";
    }
    foreach (DB::table('social_platforms')->pluck('code') as $code) {
        AuditFieldNames::label("social.{$code}") ?? $nameless[] = "social.{$code}";
        AuditFieldNames::label("link.{$code}") ?? $nameless[] = "link.{$code}";
    }
    foreach (DB::table('data_providers')->distinct()->pluck('service') as $service) {
        AuditFieldNames::label("provider.{$service}") ?? $nameless[] = "provider.{$service}";
    }
    foreach (array_keys(EmailCatalog::templates()) as $key) {
        foreach (['subject', 'body_html', 'is_active'] as $part) {
            AuditFieldNames::label("{$key}.{$part}") ?? $nameless[] = "{$key}.{$part}";
        }
    }

    expect($nameless)->toBe([])
        ->and(AuditFieldNames::label('feature.chatbot'))->toBe('الميزة «'.DB::table('feature_flags')->where('key', 'chatbot')->value('description_ar').'»')
        ->and(AuditFieldNames::label('social.website'))->toBe('رابط الموقع الإلكتروني')
        ->and(AuditFieldNames::label('general.site_name'))->toBe(SettingsCatalog::fields()['general.site_name']['label'])
        ->and(AuditFieldNames::label('admin_login_code.subject'))->toBe(EmailCatalog::templates()['admin_login_code']['name'].': الموضوع')
        ->and(AuditFieldNames::label('provider.weather'))->toBe('مصدر الطقس')
        ->and(AuditFieldNames::label('feature.nothing'))->toBeNull()
        ->and(AuditFieldNames::label('nothing.subject'))->toBeNull()
        ->and(AuditFieldNames::label('something_new'))->toBeNull();
});

test('the audit screen shows a field by its Arabic name, an unnamed one as stored, and the values as they are', function () {
    $admin = User::factory()->staff()->create();
    $id = DB::table('audit_log')->insertGetId(['actor_user_id' => $admin->id, 'action' => 'update', 'subject_table' => 'posts', 'subject_id' => 7]);
    DB::table('audit_log_changes')->insert([
        ['audit_id' => $id, 'field' => 'slug', 'old_value' => 'old-slug', 'new_value' => 'new-slug'],
        ['audit_id' => $id, 'field' => 'something_new', 'old_value' => null, 'new_value' => 'x'],
    ]);

    $html = $this->actingAs($admin)->get('/'.config('raqam.admin_path')."/audit-logs/{$id}")->assertOk()->getContent();

    expect($html)->toContain('الرابط المختصر')->toContain('old-slug')->toContain('new-slug')
        ->and($html)->not->toMatch('/>\s*slug\s*</')
        ->and($html)->toMatch('/dir="ltr"[^>]*data-latin-ok[^>]*>.*?something_new/s');
});

test('the audit screen\'s search finds a change by the Arabic name of its field', function () {
    $admin = User::factory()->staff()->create();
    $slug = DB::table('audit_log')->insertGetId(['actor_user_id' => $admin->id, 'action' => 'update', 'subject_table' => 'posts', 'subject_id' => 7]);
    $title = DB::table('audit_log')->insertGetId(['actor_user_id' => $admin->id, 'action' => 'update', 'subject_table' => 'posts', 'subject_id' => 8]);
    DB::table('audit_log_changes')->insert([
        ['audit_id' => $slug, 'field' => 'slug', 'old_value' => 'a', 'new_value' => 'b'],
        ['audit_id' => $title, 'field' => 'title', 'old_value' => 'a', 'new_value' => 'b'],
    ]);

    $found = (new ReflectionMethod(AuditLogResource::class, 'search'))->invoke(null, AuditLog::query(), 'الرابط المختصر')->pluck('id')->all();

    expect($found)->toBe([$slug]);
});
