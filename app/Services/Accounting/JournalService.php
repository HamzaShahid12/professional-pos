<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\DocumentNumberService;
use App\Services\Settings\SettingsService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY writer of journal entries. Every entry balances to the cent, is dated outside the closed period
 * and is immutable once written; corrections are reversing entries.
 */
class JournalService
{
    public function __construct(private ChartOfAccounts $chart, private DocumentNumberService $numbers, private SettingsService $settings) {}

    /**
     * @param  array<int, array{account:int|string, debit?:string|int|float|null, credit?:string|int|float|null, memo?:?string}>  $lines
     *                                                                                                                                `account` is an account id, or a system key such as 'cash'.
     * @return JournalEntry|null null when every line is zero (nothing to record)
     */
    public function post(
        string $date, string $description, array $lines, ?string $sourceType = null, ?int $sourceId = null,
        string $kind = 'auto', ?int $branchId = null, ?int $reversesId = null,
    ): ?JournalEntry {
        $this->assertOpenPeriod($date);

        $clean = [];
        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();
        foreach ($lines as $i => $line) {
            $debit = BigDecimal::of((string) ($line['debit'] ?? '0'));
            $credit = BigDecimal::of((string) ($line['credit'] ?? '0'));
            if ($debit->isNegative() || $credit->isNegative() || ($debit->isPositive() && $credit->isPositive())) {
                throw ValidationException::withMessages(["lines.$i" => 'A line is either a debit or a credit, with a positive amount.']);
            }
            if ($debit->isZero() && $credit->isZero()) {
                continue;
            }
            $clean[] = ['account' => $line['account'], 'debit' => $debit->toScale(4), 'credit' => $credit->toScale(4), 'memo' => $line['memo'] ?? null];
            $debits = $debits->plus($debit);
            $credits = $credits->plus($credit);
        }

        if ($clean === []) {
            return null;
        }
        if (count($clean) < 2 || ! $debits->isEqualTo($credits)) {
            throw ValidationException::withMessages(['lines' => 'Debits ('.$debits->toScale(2).') must equal credits ('.$credits->toScale(2).').']);
        }

        return DB::transaction(function () use ($date, $description, $clean, $sourceType, $sourceId, $kind, $branchId, $reversesId, $debits) {
            $ids = [];
            foreach ($clean as $i => $line) {
                $ids[$i] = is_int($line['account']) || ctype_digit((string) $line['account'])
                    ? (int) $line['account']
                    : $this->chart->id((string) $line['account']);
            }

            $accounts = Account::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');
            foreach ($ids as $i => $id) {
                $account = $accounts->get($id);
                if (! $account) {
                    throw ValidationException::withMessages(["lines.$i.account" => 'Choose an account from your chart of accounts.']);
                }
                if (! $account->is_active) {
                    throw ValidationException::withMessages(["lines.$i.account" => "{$account->name} is inactive."]);
                }
            }

            $entry = JournalEntry::create([
                'branch_id' => $branchId, 'entry_no' => $this->numbers->next('journal', 'JE'), 'entry_date' => $date,
                'description' => mb_substr($description, 0, 255), 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'reverses_id' => $reversesId, 'total' => (string) $debits->toScale(4), 'created_by' => auth()->id(),
            ]);

            foreach ($clean as $i => $line) {
                $entry->lines()->create([
                    'account_id' => $ids[$i], 'debit' => (string) $line['debit'], 'credit' => (string) $line['credit'],
                    'memo' => $line['memo'] ? mb_substr($line['memo'], 0, 255) : null,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * A journal typed in by a person. It may not touch the control accounts (receivable, payable, inventory): those
     * are owned by the customer, supplier and stock ledgers and must always agree with them.
     */
    public function manual(string $date, string $description, array $lines): JournalEntry
    {
        $locked = array_map(fn ($k) => $this->chart->id($k), ['receivable', 'payable', 'inventory']);
        foreach ($lines as $i => $line) {
            if (in_array((int) $line['account'], $locked, true)) {
                throw ValidationException::withMessages(["lines.$i.account" => 'Receivable, payable and inventory are controlled by customer, supplier and stock records. Use a payment, adjustment or stock adjustment instead.']);
            }
        }

        return $this->post($date, $description, $lines, 'manual', null, 'manual')
            ?? throw ValidationException::withMessages(['lines' => 'Enter at least one amount.']);
    }

    /** Post the mirror image of an entry. An entry can be reversed once, and a reversal cannot be reversed. */
    public function reverse(JournalEntry $entry, string $date, ?string $description = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $description) {
            $original = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($original->kind === 'reversal') {
                throw ValidationException::withMessages(['entry' => 'A reversal cannot be reversed. Post a new entry instead.']);
            }
            if (JournalEntry::query()->where('reverses_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['entry' => "{$original->entry_no} has already been reversed."]);
            }

            $lines = $original->lines()->get()->map(fn ($l) => [
                'account' => (int) $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'memo' => $l->memo,
            ])->all();

            return $this->post(
                $date, $description ?: 'Reversal of '.$original->entry_no.' ('.$original->description.')', $lines,
                $original->source_type, $original->source_id, 'reversal', $original->branch_id, $original->id,
            );
        });
    }

    /** Reverse the automatic entry of a document (used when a sale is voided). Null if the document has none. */
    public function reverseSource(string $sourceType, int $sourceId, string $date, ?string $description = null): ?JournalEntry
    {
        $entry = JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('kind', 'auto')->first();

        return $entry ? $this->reverse($entry, $date, $description) : null;
    }

    public function lockDate(): ?string
    {
        $value = (string) $this->settings->get('accounting.lock_date', '');

        return $value === '' ? null : $value;
    }

    public function assertOpenPeriod(string $date): void
    {
        $lock = $this->lockDate();
        if ($lock && $date <= $lock) {
            throw ValidationException::withMessages(['entry_date' => "The books are closed up to $lock. Choose a later date."]);
        }
    }
}
