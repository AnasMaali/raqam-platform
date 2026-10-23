<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A newsletter subscriber (decision 19). Written only by App\Newsletter: confirmed by code, kept in step with Brevo. */
class Subscriber extends Model
{
    public const CREATED_AT = null;

    public const STATUSES = ['pending' => 'بانتظار التأكيد', 'active' => 'مشترك', 'unsubscribed' => 'ألغى الاشتراك', 'bounced' => 'بريد مرتد'];

    public const PLACEMENTS = ['footer' => 'الفوتر', 'post_end' => 'نهاية مادة', 'newsletter_page' => 'صفحة النشرة'];

    protected function casts(): array
    {
        return ['subscribed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'unsubscribed_at' => 'immutable_datetime'];
    }
}
