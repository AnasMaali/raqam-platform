<?php

namespace App\Http\Controllers\Api;

use App\Api\Presenter;
use App\Navigation\HomeBoard;
use Illuminate\Http\JsonResponse;

/** The home page (21.11): its blocks in the admin's order, each with what it shows now. */
class HomeApiController extends ApiController
{
    public function __construct(Presenter $presenter, private HomeBoard $board)
    {
        parent::__construct($presenter);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->board->blocks()]);
    }
}
