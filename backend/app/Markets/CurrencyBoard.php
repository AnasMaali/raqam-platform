<?php

namespace App\Markets;

use App\Models\Currency;
use App\Models\RateBoardCurrency;
use Illuminate\Support\Facades\DB;

/** Adds a currency to the board (11.1). Only one the active source publishes, or a pegged one, can be added. */
final class CurrencyBoard
{
    /** @return array<string, string> code => label, for the "add" list */
    public static function addable(): array
    {
        $supported = CurrencySources::active()?->supports() ?? [];
        $pegged = DB::table('currency_pegs')->pluck('currency_code')->map(fn ($c) => trim($c))->all();
        $taken = DB::table('rate_board_currencies')->pluck('currency_code')->map(fn ($c) => trim($c))->all();

        return collect([...$supported, ...$pegged])->unique()->diff($taken)->sort()->values()
            ->mapWithKeys(fn (string $code) => [$code => $code.' — '.(DB::table('currencies')->where('code', $code)->value('name_ar') ?? CurrencyCatalog::name($code))])
            ->all();
    }

    /** @throws RateRejected */
    public static function add(string $code, string $name, string $symbol, int $decimals, int $buyBp, int $sellBp): RateBoardCurrency
    {
        if (! array_key_exists($code, self::addable())) {
            throw new RateRejected('لا يمكن إضافة هذه العملة: مصدر الأسعار لا ينشرها أو هي على اللوحة.');
        }

        return DB::transaction(function () use ($code, $name, $symbol, $decimals, $buyBp, $sellBp) {
            $currency = Currency::query()->find($code);
            if ($currency === null) {
                Currency::query()->create(['code' => $code, 'name_ar' => $name, 'symbol' => $symbol, 'minor_units' => 2]);
            } else {
                $currency->update(['name_ar' => $name, 'symbol' => $symbol]);
            }

            return RateBoardCurrency::query()->create([
                'currency_code' => $code, 'display_decimals' => $decimals, 'buy_spread_bp' => $buyBp, 'sell_spread_bp' => $sellBp,
                'sort_order' => (int) RateBoardCurrency::query()->max('sort_order') + 1, 'is_active' => true,
            ]);
        });
    }
}
