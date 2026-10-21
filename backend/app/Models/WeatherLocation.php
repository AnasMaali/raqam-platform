<?php

namespace App\Models;

use App\Audit\Audited;
use App\Weather\WeatherBoard;
use Illuminate\Database\Eloquent\Model;

/** A city on the weather list (12.1): its place in the order, whether it shows, and the default (Nablus). */
class WeatherLocation extends Model
{
    use Audited;

    public $timestamps = false;

    protected $primaryKey = 'city_id';

    public $incrementing = false;

    protected $fillable = ['city_id', 'is_default', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer', 'city_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => WeatherBoard::changed());
        static::deleted(fn () => WeatherBoard::changed());
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
