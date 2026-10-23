<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The chatbot's bot_entries (decision 18). Written through App\Chat, which checks the rules and refreshes the knowledge base. */
class BotEntry extends Model
{
    protected $table = 'bot_entries';

    public $timestamps = false;
}
