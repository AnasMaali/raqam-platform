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

    /** Set while a screen saves the character, its poses and its SEO together, so that save announces itself once at the end. */
    public bool $holdWebhook = false;

    protected static function booted(): void
    {
        static::updated(function (self $character) {
            if (! $character->holdWebhook) {
                $character->announce(array_keys($character->getChanges()));
            }
        });
    }

    /**
     * A character's cards sit on many pages at once (the awareness page, every item linking to them, lists, texts), so
     * hiding or showing it, or any edit of a visible one or of its poses, asks the site to refresh everything rather
     * than guessing the addresses. A hidden character's cards are not on the site, so editing it sends nothing.
     *
     * @param  list<string>  $changed  the columns the save wrote
     */
    public function announce(array $changed, bool $posesChanged = false): void
    {
        $changed = array_diff($changed, ['created_at', 'updated_at', 'seo_meta_id']);

        if (in_array('is_active', $changed, true)) {
            SendSiteWebhook::notify(['type' => 'all', 'change' => $this->is_active ? 'character_shown' : 'character_hidden', 'id' => $this->id, 'paths' => []]);
        } elseif ($this->is_active && ($changed !== [] || $posesChanged)) {
            SendSiteWebhook::notify(['type' => 'all', 'change' => 'character_updated', 'id' => $this->id, 'paths' => []]);
        }
    }

    public function rive(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'rive_media_id');
    }
}
