<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * A feature switch (database/schema/03-reference.sql, decision 21.14). The database refuses a flag that
 * is on while the flag it depends on is off, and the other way round.
 */
class FeatureFlag extends Model
{
    use Audited;

    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['is_enabled', 'updated_by'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

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
        if (! $this->wasChanged('is_enabled')) {
            return [];
        }

        return ['feature.'.$this->key => [$this->getOriginal('is_enabled'), $this->is_enabled]];
    }
}
