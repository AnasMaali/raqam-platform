<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The facts about a video that lives on YouTube (youtube_videos): written by the library, not edited by hand. */
class YoutubeVideo extends Model
{
    protected $primaryKey = 'video_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_short' => 'boolean', 'published_on_youtube_at' => 'datetime', 'last_checked_at' => 'datetime', 'fetched_at' => 'datetime'];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
