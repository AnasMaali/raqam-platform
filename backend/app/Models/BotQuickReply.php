<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** The chatbot's bot_quick_replies (decision 18). Written through App\Chat, which checks the rules and refreshes the knowledge base. */
class BotQuickReply extends Model
{
    use Audited;

    protected $table = 'bot_quick_replies';

    public $timestamps = false;

    protected $fillable = ['label_ar', 'action_type', 'target_entry_id', 'target_service_id', 'target_page_id', 'target_url', 'is_root', 'sort_order', 'is_active'];
}
