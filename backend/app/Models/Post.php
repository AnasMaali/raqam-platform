<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Supertype of every content kind: database/schema/06-content.sql.
 * kind_code is never mass assignable: it is set once on creation and the database refuses to change it.
 */
class Post extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'title', 'subtitle', 'slug', 'excerpt', 'body', 'cover_media_id',
        'status', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(PostKind::class, 'kind_code');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }

    public function episode(): HasOne
    {
        return $this->hasOne(PodcastEpisode::class);
    }

    public function story(): HasOne
    {
        return $this->hasOne(SuccessStory::class);
    }
}
