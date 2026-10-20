<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Supertype of every kind of content (database/schema/06-content.sql). kind_code and the user stamps are
 * never mass assignable: the kind is set once at creation (the database refuses to change it) and the
 * stamps come from the signed-in admin. Saving goes through App\Content\PostSaver, which also looks after
 * the kind's own table, the revision, the body references and the redirect.
 */
class Post extends Model
{
    use Audited {
        auditChanges as private auditedChanges;
    }
    use HasSeoMeta, SoftDeletes;

    public const STATUSES = ['draft' => 'مسودة', 'scheduled' => 'مجدول', 'published' => 'منشور', 'archived' => 'مؤرشف'];

    /** How long a post stays in the trash before it is deleted for good (decision 3). */
    public const TRASH_DAYS = 90;

    protected $fillable = [
        'category_id', 'title', 'subtitle', 'slug', 'excerpt', 'body', 'cover_media_id', 'primary_video_id', 'status', 'published_at',
        'is_featured', 'breaking_until', 'sponsor_organization_id', 'correction_note', 'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'breaking_until' => 'datetime',
            'corrected_at' => 'datetime',
            'is_featured' => 'boolean',
        ];
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

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'sponsor_organization_id');
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'post_authors')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tags');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class)->orderByDesc('revision_no');
    }

    public function isBreaking(): bool
    {
        return $this->breaking_until !== null && $this->breaking_until->isFuture();
    }

    public function isSponsored(): bool
    {
        return $this->sponsor_organization_id !== null;
    }

    /** Live and visible to visitors: published, its time has come, not in the trash, and not the card of a hidden character. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('published_at', '<=', now())->whereNull('deleted_at')
            ->whereNotIn($query->qualifyColumn('id'), self::hiddenCharacterCards());
    }

    /** Reachable by its address: live, or archived (an archived item stays at its address but leaves every list). */
    public function scopeViewable(Builder $query): Builder
    {
        return $query->whereIn('status', ['published', 'archived'])->where('published_at', '<=', now())->whereNull('deleted_at')
            ->whereNotIn($query->qualifyColumn('id'), self::hiddenCharacterCards());
    }

    /**
     * The ids of the awareness cards whose character is hidden. They leave the site with the character: every
     * list, their own address, and every other item that points at them, so no link leads to a missing page.
     */
    public static function hiddenCharacterCards(): QueryBuilder
    {
        return DB::table('awareness_cards')->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')
            ->join('characters', 'characters.id', '=', 'character_poses.character_id')->where('characters.is_active', false)->select('awareness_cards.post_id');
    }

    // ---- audit ---------------------------------------------------------------------------------------

    protected function auditIgnored(): array
    {
        return ['updated_at', 'created_at', 'deleted_at', 'body', 'search_text'];
    }

    protected function auditAction(string $event): string
    {
        if ($event === 'update' && $this->wasChanged('status')) {
            return match (true) {
                $this->status === 'published' => 'publish',
                $this->getOriginal('status') === 'published' => 'unpublish',
                default => 'update',
            };
        }

        return $event;
    }

    /** The text of an article is not copied into the log (it is in the revisions); its size changing is. */
    protected function auditChanges(string $event): array
    {
        $changes = $this->auditedChanges($event);

        if ($event === 'update' && $this->wasChanged('body')) {
            $changes['body_length'] = [mb_strlen((string) $this->getOriginal('body')), mb_strlen($this->body)];
        }

        return $changes;
    }
}
