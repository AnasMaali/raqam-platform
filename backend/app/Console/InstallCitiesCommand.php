<?php

namespace App\Console;

use App\Seo\SitePaths;
use App\Weather\WeatherBoard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The twelve weather cities of decision 12.1, Nablus the default. A city that exists (by its address) is left as the
 * admin made it; only a missing one is added. Safe to run at every deploy.
 */
class InstallCitiesCommand extends Command
{
    protected $signature = 'cities:install';

    protected $description = 'Add any of the twelve weather cities that was never there (one the admin removed stays removed)';

    /** name, address, latitude, longitude (as in the reference seed) */
    public const CITIES = [
        ['نابلس', 'نابلس', '32.221100', '35.254400'],
        ['جنين', 'جنين', '32.464600', '35.293900'],
        ['طولكرم', 'طولكرم', '32.310400', '35.028600'],
        ['قلقيلية', 'قلقيلية', '32.189700', '34.970600'],
        ['سلفيت', 'سلفيت', '32.083300', '35.180600'],
        ['رام الله والبيرة', 'رام-الله-والبيرة', '31.903800', '35.203400'],
        ['أريحا', 'أريحا', '31.866700', '35.450000'],
        ['القدس', 'القدس', '31.768300', '35.213700'],
        ['بيت لحم', 'بيت-لحم', '31.705400', '35.202400'],
        ['الخليل', 'الخليل', '31.532600', '35.099800'],
        ['طوباس', 'طوباس', '32.320900', '35.369700'],
        ['غزة', 'غزة', '31.501700', '34.466800'],
    ];

    public function handle(): int
    {
        $added = 0;
        DB::transaction(function () use (&$added) {
            foreach (self::CITIES as $i => [$name, $slug, $lat, $lon]) {
                // The city is there (with its weather, or kept only for a company after the admin removed its weather),
                // or the admin removed it (its page answers 410, 12.1): either way the admin's choice stands.
                $removed = DB::table('redirects')->where('from_path', SitePaths::city($slug))->where('status_code', 410)->exists();
                if ($removed || DB::table('cities')->where('slug', $slug)->orWhere('name_ar', $name)->exists()) {
                    continue;
                }
                $id = DB::table('cities')->insertGetId(['name_ar' => $name, 'slug' => $slug, 'latitude' => $lat, 'longitude' => $lon]);
                $default = $slug === 'نابلس' && ! DB::table('weather_locations')->where('is_default', true)->exists();
                DB::table('weather_locations')->insert(['city_id' => $id, 'is_default' => $default, 'sort_order' => (int) DB::table('weather_locations')->max('sort_order') + 1]);
                $added++;
            }
        });
        WeatherBoard::changed();

        $this->info("Added {$added} weather cit".($added === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
