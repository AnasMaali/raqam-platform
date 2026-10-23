<?php

namespace App\Models;

use App\Audit\Audited;
use App\Chat\BotLibrary;
use App\Chat\KnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A question of the FAQ page (28) or of one service (14.7). The page's questions feed the chatbot (18.4): every change
 * makes sure each has its entry and refreshes the knowledge base.
 */
class Faq extends Model
{
    use Audited;

    protected $fillable = ['service_id', 'question', 'answer', 'sort_order', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        $refresh = function () {
            BotLibrary::syncFaqs();
            KnowledgeBase::changed();
        };
        static::saved($refresh);
        static::deleted($refresh);
    }

    public function scopeGeneral(Builder $query): Builder
    {
        return $query->whereNull('service_id');
    }
}
