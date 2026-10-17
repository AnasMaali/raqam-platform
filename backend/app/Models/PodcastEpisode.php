<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Subtype of a post of kind "episode": database/schema/06-content.sql. */
class PodcastEpisode extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'post_id';

    protected $fillable = ['season_id', 'episode_number'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
