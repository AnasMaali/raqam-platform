<?php

namespace App\Models;

use App\Audit\Audited;
use App\Jobs\GenerateMediaVariants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One file of the media library (database/schema/02-media.sql). Rows are made by App\Media\MediaUploader,
 * which checks, cleans and re-encodes the file first; nothing else should insert them.
 */
class MediaAsset extends Model
{
    use Audited, SoftDeletes;

    /** Only what an admin may edit afterwards. The file itself, its type and its place never change. */
    protected $fillable = ['alt_text', 'caption', 'credit', 'focal_x', 'focal_y'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'duration_seconds' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // A new focal point changes how the square and share crops are cut, so they are cut again.
        static::updated(function (self $asset) {
            if ($asset->isRaster() && ($asset->wasChanged('focal_x') || $asset->wasChanged('focal_y'))) {
                GenerateMediaVariants::dispatch($asset->id)->afterCommit();
            }
        });
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class, 'media_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** A raster image: the kind that gets renditions (not SVG, not PDF). */
    public function isRaster(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true);
    }

    /** Public images an editor can put on a page: the pool the pickers draw from. */
    public function scopePublicImages(Builder $query): Builder
    {
        return $query->where('visibility', 'public')->where('mime_type', 'like', 'image/%');
    }

    /** Search by file name, alt text, caption and credit (9.1). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn (Builder $q) => $q
            ->where('original_name', 'ilike', $like)
            ->orWhere('alt_text', 'ilike', $like)
            ->orWhere('caption', 'ilike', $like)
            ->orWhere('credit', 'ilike', $like));
    }

    protected function auditIgnored(): array
    {
        return ['updated_at', 'created_at', 'deleted_at'];
    }
}
