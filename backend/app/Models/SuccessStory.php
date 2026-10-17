<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Subtype of a post of kind "story": database/schema/06-content.sql. */
class SuccessStory extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'post_id';

    protected $fillable = ['organization_id', 'person_name'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
