<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * The subject and body of one system message as the admin edited them (20.7). Made from App\Mail\EmailCatalog the
 * first time it is needed; saved only through App\Mail\EmailTemplates, which checks the variables.
 */
class EmailTemplate extends Model
{
    use Audited;

    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'name_ar', 'audience', 'subject', 'body_html', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Its key is not a number, so each change is named after the template instead: "admin_login_code.subject". */
    protected function auditSubject(): ?array
    {
        return null;
    }

    protected function auditChanges(string $event): array
    {
        $fields = ['subject', 'body_html', 'is_active'];
        $changes = [];
        foreach ($fields as $field) {
            if ($event === 'create' || ($event === 'update' && $this->wasChanged($field))) {
                $changes["{$this->key}.{$field}"] = [$event === 'create' ? null : $this->getOriginal($field), $this->getAttribute($field)];
            }
        }

        return $changes;
    }
}
