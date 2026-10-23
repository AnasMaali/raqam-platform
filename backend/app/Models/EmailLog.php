<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One system message and what became of it (20.9): kept 90 days (emails:prune). Written only by App\Mail\SystemMail. */
class EmailLog extends Model
{
    protected $table = 'email_log';

    public $timestamps = false;

    public const STATUSES = ['queued' => 'بالانتظار', 'sent' => 'أُرسلت', 'failed' => 'فشلت'];

    protected function casts(): array
    {
        return ['queued_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_key', 'key');
    }
}
