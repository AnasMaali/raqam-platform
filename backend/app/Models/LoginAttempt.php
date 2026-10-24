<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One outcome of a sign-in to the panel (22.16), written by App\Auth\LoginAttempts. Read only. */
class LoginAttempt extends Model
{
    use ReadOnlyRecord;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'succeeded' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
