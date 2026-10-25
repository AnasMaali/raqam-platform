<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * The chatbot's bot_entries (decision 18). Written through App\Chat, which checks the rules, audits the save and refreshes
 * the knowledge base; the trait audits what goes through the model (deleting an entry in the panel, 22.14).
 */
class BotEntry extends Model
{
    use Audited;

    protected $table = 'bot_entries';

    public $timestamps = false;
}
