<?php

namespace App\Services\Parties;

use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The ONLY writer of party balances. Each posting locks the party row, appends an immutable ledger entry and
 * updates `balance` in the same transaction, so balance always equals SUM(party_ledger_entries.amount).
 *
 * Sign convention (both parties): positive amount increases the balance.
 *   customer balance > 0  -> customer owes us      | supplier balance > 0 -> we owe the supplier
 */
class LedgerService
{
    public const ENTRY_TYPES = ['opening', 'invoice', 'purchase', 'sale_return', 'purchase_return', 'payment', 'adjustment'];

    /** @return class-string<Model> */
    public static function modelFor(string $type): string
    {
        return match ($type) {
            Customer::PARTY_TYPE => Customer::class,
            Supplier::PARTY_TYPE => Supplier::class,
            default => throw new InvalidArgumentException("Unknown party type '$type'."),
        };
    }

    public function post(
        Model $party, string $entryType, string $amount, string $date, ?string $description = null,
        ?string $referenceType = null, ?int $referenceId = null,
    ): PartyLedgerEntry {
        if (! in_array($entryType, self::ENTRY_TYPES, true)) {
            throw new InvalidArgumentException("Unknown ledger entry type '$entryType'.");
        }

        $value = BigDecimal::of($amount);
        if ($value->isZero()) {
            throw ValidationException::withMessages(['amount' => 'The amount cannot be zero.']);
        }

        return DB::transaction(function () use ($party, $entryType, $value, $date, $description, $referenceType, $referenceId) {
            $locked = $party::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            $balance = BigDecimal::of($locked->balance)->plus($value)->toScale(4);

            $entry = PartyLedgerEntry::create([
                'party_type' => $party::PARTY_TYPE, 'party_id' => $locked->id, 'entry_type' => $entryType,
                'entry_date' => $date, 'amount' => (string) $value->toScale(4), 'balance_after' => (string) $balance,
                'description' => $description, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'created_by' => auth()->id(),
            ]);

            $locked->forceFill(['balance' => (string) $balance])->save();
            $party->setAttribute('balance', (string) $balance);

            return $entry;
        });
    }

    /** How much more a customer may owe before reaching the credit limit (limit 0 = cash only). */
    public function creditAvailable(Customer $customer): string
    {
        $available = BigDecimal::of($customer->credit_limit)->minus($customer->balance);

        return (string) ($available->isNegative() ? BigDecimal::zero() : $available)->toScale(4);
    }

    /**
     * Statement for a date range: balance brought forward, the entries (oldest first) and the closing balance.
     *
     * @return array{opening:string,closing:string,entries:\Illuminate\Pagination\LengthAwarePaginator}
     */
    public function statement(Model $party, ?string $from, ?string $to, int $perPage = 25): array
    {
        $base = fn () => PartyLedgerEntry::query()->where('party_type', $party::PARTY_TYPE)->where('party_id', $party->getKey());

        $opening = $from
            ? ($base()->whereDate('entry_date', '<', $from)->sum('amount'))
            : '0';

        $entries = $base()->with('author:id,name')
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')->paginate($perPage)->withQueryString();

        $closing = BigDecimal::of((string) $opening)->plus($base()
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))->sum('amount'));

        return [
            'opening' => (string) BigDecimal::of((string) $opening)->toScale(4),
            'closing' => (string) $closing->toScale(4),
            'entries' => $entries,
        ];
    }
}
