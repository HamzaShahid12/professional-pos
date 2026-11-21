<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use App\Support\Rounding;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rate convention: `rate` = number of BASE-currency units that equal 1 unit of the currency.
 * Base currency rate is always 1. All arithmetic uses brick/math (arbitrary precision, no floats).
 */
class CurrencyService
{
    /** Set a new current rate and append it to the immutable history. Old history rows are never touched. */
    public function setRate(Currency $currency, string $rate, ?CarbonInterface $effectiveAt = null, ?User $by = null, string $source = 'manual'): ExchangeRate
    {
        if ($currency->is_base) {
            throw ValidationException::withMessages(['rate' => 'The base currency rate is fixed at 1.']);
        }

        $value = BigDecimal::of($rate);
        if ($value->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['rate' => 'Exchange rate must be greater than zero.']);
        }

        return DB::transaction(function () use ($currency, $value, $effectiveAt, $by, $source) {
            $locked = Currency::query()->whereKey($currency->getKey())->lockForUpdate()->firstOrFail();
            $effectiveAt = $effectiveAt ?? Carbon::now();

            $history = ExchangeRate::create([
                'business_id' => $locked->business_id,
                'currency_id' => $locked->id,
                'rate' => (string) $value->toScale(8, Rounding::halfUp()),
                'effective_at' => $effectiveAt,
                'source' => $source,
                'created_by' => $by?->id,
            ]);

            // Only move the "current" rate when this entry is the newest one.
            $newest = ExchangeRate::query()->where('currency_id', $locked->id)
                ->orderByDesc('effective_at')->orderByDesc('id')->first();
            if ($newest && $newest->is($history)) {
                $locked->update(['exchange_rate' => $history->rate]);
            }

            ActivityLog::record('currency.rate_set', $locked, ['rate' => $history->rate]);

            return $history;
        });
    }

    /** Rate that was in force at a point in time (falls back to the current rate if there is no earlier history). */
    public function rateAt(Currency $currency, ?CarbonInterface $at = null): string
    {
        if ($currency->is_base) {
            return '1';
        }
        if ($at === null) {
            return (string) $currency->exchange_rate;
        }

        $row = ExchangeRate::query()->where('currency_id', $currency->id)
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('id')->first();

        return $row ? (string) $row->rate : (string) $currency->exchange_rate;
    }

    /** Convert an amount between two currencies of the same business, rounded to the target's decimals. */
    public function convert(string $amount, Currency $from, Currency $to, ?CarbonInterface $at = null): string
    {
        if ($from->is($to)) {
            return (string) BigDecimal::of($amount)->toScale($to->decimal_places, Rounding::halfUp());
        }

        $inBase = BigDecimal::of($amount)->multipliedBy($this->rateAt($from, $at));

        return (string) $inBase->dividedBy($this->rateAt($to, $at), $to->decimal_places, Rounding::halfUp());
    }

    /** Convert to the business base currency. */
    public function toBase(string $amount, Currency $from, Currency $base, ?CarbonInterface $at = null): string
    {
        return $this->convert($amount, $from, $base, $at);
    }

    /** Format with the currency symbol, e.g. "Rs 1,250.00". */
    public function format(string $amount, Currency $currency): string
    {
        $scaled = BigDecimal::of($amount)->toScale($currency->decimal_places, Rounding::halfUp());

        return $currency->symbol.' '.number_format((float) (string) $scaled, $currency->decimal_places);
    }
}
