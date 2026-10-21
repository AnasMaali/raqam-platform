<?php

namespace App\Models;

use App\Audit\Audited;
use App\Weather\WeatherBoard;
use Illuminate\Database\Eloquent\Model;

/** A city (reference data): weather cities (12.1) and organisations' cities. Its address (slug) never changes. */
class City extends Model
{
    use Audited;

    public $timestamps = false;

    protected $fillable = ['name_ar', 'slug', 'latitude', 'longitude'];

    protected static function booted(): void
    {
        static::saved(fn () => WeatherBoard::changed());
    }

    public function weather()
    {
        return $this->hasOne(WeatherLocation::class, 'city_id');
    }
}
