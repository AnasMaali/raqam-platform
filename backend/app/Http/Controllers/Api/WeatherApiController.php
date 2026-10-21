<?php

namespace App\Http\Controllers\Api;

use App\Api\Presenter;
use App\Weather\WeatherBoard;
use Illuminate\Http\JsonResponse;

/**
 * Weather for the site (decisions 12.4–12.11): the cities, the header widget and a page per city. Read from our
 * own tables only. Every answer carries the credit OpenWeather requires ("Weather data © OpenWeather").
 */
class WeatherApiController extends ApiController
{
    public function __construct(Presenter $presenter, private WeatherBoard $board)
    {
        parent::__construct($presenter);
    }

    public function cities(): JsonResponse
    {
        return response()->json(['data' => $this->board->cities(), 'meta' => $this->board->meta()]);
    }

    public function widget(): JsonResponse
    {
        return response()->json(['data' => $this->board->widget(), 'meta' => $this->board->meta()]);
    }

    public function show(string $slug): JsonResponse
    {
        $city = mb_strlen($slug) <= 120 ? $this->board->show($slug) : null;

        return $city === null ? $this->notFound() : response()->json(['data' => $city, 'meta' => $this->board->meta()]);
    }
}
