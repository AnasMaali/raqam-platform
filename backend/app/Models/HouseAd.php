<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** The «أعلن معنا» banner a slot shows while no campaign fills it, when the slot is set to do so (decision 13.13). */
class HouseAd extends Model
{
    use Audited { auditChanges as baseAuditChanges; }

    public const CREATED_AT = null;

    protected $primaryKey = 'placement_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['placement_code', 'desktop_media_id', 'mobile_media_id', 'target_path', 'is_active'];

    protected function casts(): array
    {
        return ['desktop_media_id' => 'integer', 'mobile_media_id' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    protected function auditChanges(string $event): array
    {
        $changes = $this->baseAuditChanges($event);

        return $changes === [] && $event !== 'delete' ? [] : ['house_ad' => [$this->placement_code, $this->placement_code]] + $changes;
    }
}
