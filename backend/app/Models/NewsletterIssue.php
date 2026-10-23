<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One weekly newsletter (19.6). Made and changed only through App\Newsletter\NewsletterIssues. */
class NewsletterIssue extends Model
{
    protected function casts(): array
    {
        return ['week_start' => 'immutable_date', 'sent_at' => 'immutable_datetime'];
    }
}
