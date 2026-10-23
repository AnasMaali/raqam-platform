<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The chatbot's bot_messages (decision 18). Written through App\Chat, which checks the rules and refreshes the knowledge base. */
class BotMessage extends Model
{
    protected $table = 'bot_messages';

    public $timestamps = false;
}
