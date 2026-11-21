<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/**
 * Single place where tax is computed (the POS, purchases and reports all call this; the frontend never does).
 *
 * Rules:
 *  - Inclusive taxes are contained in the entered price: net = price * 100 / (100 + sum(inclusive rates)).
 *  - Exclusive taxes are added on top of that net amount.
 *  - All rates apply to the same net base (they are additive, not compounded).
 *  - Each tax is rounded half-up to $scale; the inclusive rounding remainder goes to the last inclusive tax so
 *    that net + inclusive taxes always equals the entered price exactly.
 */
class TaxCalculator
{
    /**
     * @param  iterable<array{id?: int, name?: string, rate: string|int|float, is_inclusive: bool}>  $taxes
     * @return array{net: string, tax_total: string, gross: string, lines: array<int, array>}
     */
    public function calculate(string $amount, iterable $taxes, int $scale = 4): array
    {
        $amount = BigDecimal::of($amount);
        $taxes = collect($taxes)->values();
        $round = Rounding::halfUp();

        $inclusiveRate = BigDecimal::zero();
        foreach ($taxes->where('is_inclusive', true) as $t) {
            $inclusiveRate = $inclusiveRate->plus((string) $t['rate']);
        }

        $net = $inclusiveRate->isZero()
            ? $amount->toScale($scale, $round)
            : $amount->multipliedBy(100)->dividedBy(BigDecimal::of(100)->plus($inclusiveRate), $scale, $round);

        $lines = [];
        $inclusiveTotal = BigDecimal::zero();
        $lastInclusiveIndex = $taxes->keys()->filter(fn ($i) => $taxes[$i]['is_inclusive'])->last();
        $inclusiveBudget = $amount->minus($net); // what the inclusive taxes must add up to

        foreach ($taxes as $i => $t) {
            $tax = $net->multipliedBy((string) $t['rate'])->dividedBy(100, $scale, $round);

            if ($t['is_inclusive']) {
                if ($i === $lastInclusiveIndex) {
                    $tax = $inclusiveBudget->minus($inclusiveTotal)->toScale($scale, $round);
                }
                $inclusiveTotal = $inclusiveTotal->plus($tax);
            }

            $lines[] = [
                'id' => $t['id'] ?? null,
                'name' => $t['name'] ?? null,
                'rate' => (string) $t['rate'],
                'is_inclusive' => (bool) $t['is_inclusive'],
                'amount' => (string) $tax,
            ];
        }

        $taxTotal = BigDecimal::zero();
        foreach ($lines as $l) {
            $taxTotal = $taxTotal->plus($l['amount']);
        }

        return [
            'net' => (string) $net,
            'tax_total' => (string) $taxTotal->toScale($scale, $round),
            'gross' => (string) $net->plus($taxTotal)->toScale($scale, $round),
            'lines' => $lines,
        ];
    }
}
