<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * A typed site setting (database/schema/03-reference.sql, decision 21.13). The type rules live in the
 * table's CHECKs; App\Settings\SettingsCatalog gives each key its Arabic label and form field.
 * Never a secret: those stay in .env.
 */
class Setting extends Model
{
    use Audited;

    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'value_type', 'updated_by'];

    /** One audit row per setting, named after the setting itself rather than after the column. */
    protected function auditAction(string $event): string
    {
        return 'settings';
    }

    protected function auditSubject(): ?array
    {
        return null;
    }

    protected function auditChanges(string $event): array
    {
        if ($event === 'update' && ! $this->wasChanged('value')) {
            return [];
        }

        return [$this->key => [$event === 'create' ? null : $this->getOriginal('value'), $this->value]];
    }
}
