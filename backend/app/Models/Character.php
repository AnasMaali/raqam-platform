<?php

namespace App\Models;

use App\Audit\Audited;
use App\Jobs\SendSiteWebhook;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An awareness character (decision 7): a name, a role, a colour and up to five poses, each with a picture and maybe an animation state. */
class Character extends Model
{
    use Audited, HasSeoMeta;

    protected $fillable = ['name_ar', 'slug', 'role_ar', 'description', 'accent_color', 'rive_media_id', 'seo_meta_id', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // Hiding or showing a character adds or removes its cards on many pages at once (the awareness page, every
        // item linking to them, lists, texts), so the site refreshes everything rather than guessing the addresses.
        static::updated(function (self $character) {
            if ($character->wasChanged('is_active')) {
                SendSiteWebhook::notify(['type' => 'all', 'change' => $character->is_active ? 'character_shown' : 'character_hidden', 'id' => $character->id, 'paths' => []]);
            }
        });
    }

    public function rive(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'rive_media_id');
    }
}
