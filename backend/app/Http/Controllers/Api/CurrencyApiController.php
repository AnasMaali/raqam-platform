<?php

namespace App\Http\Controllers\Api;

use App\Api\Presenter;
use App\Markets\RateBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The currency board, converter, ticker and 30-day history (decisions 11.4–11.12). Read from our own tables only:
 * a request never waits for the bank. Rates are decimal strings (never floats) rounded to each currency's decimals.
 */
class CurrencyApiController extends ApiController
{
    public function __construct(Presenter $presenter, private RateBoard $board)
    {
        parent::__construct($presenter);
    }

    public function board(): JsonResponse
    {
        return response()->json(['data' => $this->board->rows(), 'meta' => $this->board->meta()]);
    }

    public function ticker(): JsonResponse
    {
        $items = array_map(fn (array $r) => array_intersect_key($r, array_flip(['code', 'path', 'name', 'symbol', 'mid', 'change_pct', 'direction'])), $this->board->rows());

        return response()->json(['data' => $items, 'meta' => $this->board->meta()]);
    }

    public function converter(): JsonResponse
    {
        $items = array_map(fn (array $r) => array_intersect_key($r, array_flip(['code', 'name', 'symbol', 'decimals', 'buy', 'sell', 'is_manual'])), $this->board->rows());

        return response()->json(['data' => [
            'base' => 'ILS',
            'currencies' => $items,
            // 11.7: the converter says which rate it used.
            'rules' => [
                'to_base' => ['rate' => 'buy', 'text' => 'من العملة إلى الشيكل: المبلغ × سعر الشراء.'],
                'from_base' => ['rate' => 'sell', 'text' => 'من الشيكل إلى العملة: المبلغ ÷ سعر البيع.'],
            ],
        ], 'meta' => $this->board->meta()]);
    }

    public function history(Request $request, string $code): JsonResponse
    {
        $q = $this->query($request, ['days' => ['nullable', 'integer', 'min:7', 'max:'.RateBoard::HISTORY_MAX_DAYS]]);
        $code = strtoupper($code);
        $points = $this->board->history($code, (int) ($q['days'] ?? RateBoard::HISTORY_DAYS));
        if ($points === null) {
            return $this->notFound();
        }

        $currency = DB::table('currencies')->join('rate_board_currencies', 'rate_board_currencies.currency_code', '=', 'currencies.code')
            ->where('currencies.code', $code)->first(['currencies.name_ar', 'currencies.symbol', 'rate_board_currencies.display_decimals']);

        return response()->json(['data' => [
            'code' => $code, 'name' => $currency->name_ar, 'symbol' => $currency->symbol, 'decimals' => (int) $currency->display_decimals,
            'points' => $points,
        ], 'meta' => $this->board->meta()]);
    }
}
