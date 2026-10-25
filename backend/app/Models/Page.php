<?php

namespace App\Models;

use App\Audit\Audited;
use App\Content\KeepsOldAddresses;
use App\Jobs\SendSiteWebhook;
use App\Models\Concerns\HasSeoMeta;
use App\Navigation\MenuBoard;
use App\Seo\IndexNow;
use App\Seo\SitePaths;
use Illuminate\Database\Eloquent\Model;

/**
 * A static page (decision 28): about, privacy, terms and the rest. The pages the site's code links to carry a
 * system_key and cannot be deleted or re-addressed; others can be added.
 */
class Page extends Model
{
    use Audited, HasSeoMeta, KeepsOldAddresses;

    protected $fillable = ['title', 'slug', 'body', 'is_published', 'updated_by'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    protected static function booted(): void
    {
        $tell = fn (self $page) => SendSiteWebhook::notify(['type' => 'page', 'id' => $page->id, 'change' => 'content', 'paths' => ['/'.$page->slug]]);
        static::saved($tell);
        static::deleted($tell);
        // A menu link to the page changes with its address or when it is published or unpublished (21.12, 21.16).
        static::saved(fn (self $p) => $p->wasChanged(['slug', 'is_published']) ? MenuBoard::targetChanged('page_id', $p->id) : null);
        static::deleting(fn (self $p) => MenuBoard::targetChanged('page_id', $p->id));
        // Search engines hear about a published page, an edit, and a page taken off or re-addressed (23.11).
        static::saved(function (self $p) {
            if (($p->is_published || $p->wasChanged('is_published')) && ! IndexNow::noindex($p->seo_meta_id)) {
                IndexNow::submit(['/'.$p->slug, ...($p->wasChanged('slug') ? ['/'.$p->getOriginal('slug')] : [])]);
            }
        });
        static::deleted(fn (self $p) => $p->is_published ? IndexNow::submit(['/'.$p->slug]) : null);
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    protected function auditIgnored(): array
    {
        return ['updated_at', 'created_at', 'body'];
    }

    /** Its public page (23.3); a changed slug leaves a 301 behind, a deletion a 410 (23.6). */
    public static function addressFor(string $slug): string
    {
        return SitePaths::page($slug);
    }
}
