<?php

namespace App\Models;

use App\Audit\Audited;
use App\Content\KeepsOldAddresses;
use App\Models\Concerns\HasSeoMeta;
use App\Navigation\MenuBoard;
use App\Seo\SitePaths;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A news category (decision 4): one level of sub-categories, enforced by the database. */
class Category extends Model
{
    use Audited, HasSeoMeta, KeepsOldAddresses;

    protected $fillable = ['parent_id', 'name_ar', 'slug', 'description', 'is_highlighted', 'sort_order', 'is_active', 'seo_meta_id'];

    protected function casts(): array
    {
        return ['is_highlighted' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // A menu link to the category changes with its address or when it is hidden (21.12, 21.16).
        static::saved(fn (self $c) => $c->wasChanged(['slug', 'is_active']) ? MenuBoard::targetChanged('category_id', $c->id) : null);
        static::deleting(fn (self $c) => MenuBoard::targetChanged('category_id', $c->id));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** Its public page (23.3); a changed slug leaves a 301 behind, a deletion a 410 (23.6). */
    public static function addressFor(string $slug): string
    {
        return SitePaths::category($slug);
    }
}
