<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * A message of the contact form (20.13). Kept in the panel; the visitor's address is an HMAC only. The audit trail
 * records what the admins do with it (read, archived, deleted), never the visitor's words.
 */
class ContactMessage extends Model
{
    use Audited;

    public const UPDATED_AT = null;

    public const STATUSES = ['new' => 'جديدة', 'read' => 'مقروءة', 'archived' => 'مؤرشفة'];

    protected $fillable = ['status', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected function auditIgnored(): array
    {
        return ['created_at', 'name', 'email', 'phone', 'subject', 'message', 'ip_hash'];
    }

    protected function auditChanges(string $event): array
    {
        return $event === 'create' ? [] : collect($this->getChanges())->only(['status'])
            ->mapWithKeys(fn ($new, $field) => [$field => [$this->getOriginal($field), $new]])->all();
    }
}
