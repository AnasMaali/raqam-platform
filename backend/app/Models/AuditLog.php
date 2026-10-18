<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the audit trail (database/schema/10-seo-analytics-audit.sql). Read only: the database
 * refuses UPDATE and DELETE on it, so the model offers no way to change a row either.
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AuditLogChange::class, 'audit_id');
    }
}
