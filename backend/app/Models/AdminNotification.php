<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One alert in one admin's bell (20.10). Raised only through App\Panel\AdminAlerts. */
class AdminNotification extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
