<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * An ad slot of the site (decision 13.1, 13.2): its reserved sizes (13.10), whether it is on, what an empty slot shows
 * (13.13), how often the popup may show (13.2) and whether its picture loads lazily (13.11). The six slots are fixed:
 * they are edited, never added or removed. The key is the code; the audit log names it in every change.
 */
class AdPlacement extends Model
{
    use Audited { auditChanges as baseAuditChanges; }

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name_ar', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height', 'is_enabled', 'empty_behavior', 'frequency_cap_hours', 'lazy_load'];

    protected function casts(): array
    {
        return [
            'desktop_width' => 'integer', 'desktop_height' => 'integer', 'mobile_width' => 'integer', 'mobile_height' => 'integer',
            'is_enabled' => 'boolean', 'frequency_cap_hours' => 'integer', 'lazy_load' => 'boolean',
        ];
    }

    public function houseAd()
    {
        return $this->hasOne(HouseAd::class, 'placement_code', 'code');
    }

    /** @return array{width: int, height: int}|null */
    public function size(string $device): ?array
    {
        $w = $this->{$device.'_width'};
        $h = $this->{$device.'_height'};

        return $w && $h ? ['width' => $w, 'height' => $h] : null;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    protected function auditChanges(string $event): array
    {
        $changes = $this->baseAuditChanges($event);

        return $changes === [] ? [] : ['placement' => [$this->code, $this->code]] + $changes;
    }
}
