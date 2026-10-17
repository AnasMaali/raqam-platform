<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** One of the platform's own social accounts (one row per platform). */
class SiteSocialLink extends Model
{
    use Audited;

    public $timestamps = false;

    protected $primaryKey = 'platform_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['platform_code', 'url', 'sort_order'];

    protected function auditAction(string $event): string
    {
        return 'settings';
    }

    protected function auditSubject(): ?array
    {
        return null;
    }

    protected function auditChanges(string $event): array
    {
        $field = 'social.'.$this->platform_code;

        return match ($event) {
            'create' => [$field => [null, $this->url]],
            'update' => $this->wasChanged('url') ? [$field => [$this->getOriginal('url'), $this->url]] : [],
            'delete' => [$field => [$this->url, null]],
            default => [],
        };
    }
}
