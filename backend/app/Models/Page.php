<?php

namespace App\Models;

use App\Audit\Audited;
use App\Jobs\SendSiteWebhook;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;

/**
 * A static page (decision 28): about, privacy, terms and the rest. The pages the site's code links to carry a
 * system_key and cannot be deleted or re-addressed; others can be added.
 */
class Page extends Model
{
    use Audited, HasSeoMeta;

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
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    protected function auditIgnored(): array
    {
        return ['updated_at', 'created_at', 'body'];
    }
}
