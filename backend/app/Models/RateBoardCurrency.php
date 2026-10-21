<?php

namespace App\Models;

use App\Audit\Audited;
use App\Markets\RateBoard;
use Illuminate\Database\Eloquent\Model;

/**
 * A currency on the board (decisions 11.1, 11.4): its spreads, decimals, order and whether it shows.
 * The key is the ISO code; the audit log names the currency in every change since the key is not a number.
 */
class RateBoardCurrency extends Model
{
    use Audited;

    public const CREATED_AT = null;

    protected $primaryKey = 'currency_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::saved(fn () => RateBoard::changed('board'));
        static::deleted(fn () => RateBoard::changed('board'));
    }

    protected $fillable = ['currency_code', 'buy_spread_bp', 'sell_spread_bp', 'display_decimals', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['buy_spread_bp' => 'integer', 'sell_spread_bp' => 'integer', 'display_decimals' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    protected function auditChanges(string $event): array
    {
        $changes = match ($event) {
            'create' => collect($this->getAttributes())->except($this->auditIgnored())->map(fn ($v) => [null, $v])->all(),
            'update' => collect($this->getChanges())->except($this->auditIgnored())->mapWithKeys(fn ($new, $field) => [$field => [$this->getOriginal($field), $new]])->all(),
            default => [],
        };
        if ($changes === [] && $event === 'update') {
            return [];
        }

        return ['currency_code' => [$event === 'create' ? null : trim($this->getOriginal('currency_code') ?? $this->currency_code), $event === 'delete' ? null : trim($this->currency_code)]] + $changes;
    }
}
