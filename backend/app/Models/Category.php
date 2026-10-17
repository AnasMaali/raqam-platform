<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A news category (decision 4): one level of sub-categories, enforced by the database. */
class Category extends Model
{
    use Audited, HasSeoMeta;

    protected $fillable = ['parent_id', 'name_ar', 'slug', 'description', 'is_highlighted', 'sort_order', 'is_active', 'seo_meta_id'];

    protected function casts(): array
    {
        return ['is_highlighted' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
