<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;

/**
 * Turns business documents into journal entries. Each method is called by the service that wrote the document,
 * inside the same database transaction, so a document and its books can never disagree.
 *
 * Every amount is in the business base currency. Entries balance by construction.
 */
class PostingService
{
    public function __construct(private JournalService $journal, private TenantContext $tenant) {}

    public function today(): string
    {
        return now($this->tenant->business()?->timezone ?? config('app.timezone'))->toDateString();
    }

    /** Cash tenders go to the till, everything else (bank, card, cheque, other) to the bank account. */
    public function moneyAccount(string $method): string
    {
        return $method === 'cash' ? 'cash' : 'bank';
    }

    /**
     * Completed sale: money in (net of change) and any balance on credit, against net revenue and tax collected;
     * cost of goods sold against inventory for stocked items.
     *
     * @param  array<int, array{method:string, amount:string}>  $payments
     */
    public function sale(Sale $sale, array $payments, string $date): ?JournalEntry
    {
        $lines = [];
        foreach ($payments as $p) {
            $lines[] = ['account' => $this->moneyAccount($p['method']), 'debit' => $p['amount'], 'memo' => $p['method']];
        }
        $lines[] = ['account' => 'receivable', 'debit' => $sale->balance_due];
        $lines[] = ['account' => 'sales', 'credit' => (string) BigDecimal::of($sale->total)->minus($sale->tax_total)];
        $lines[] = ['account' => 'tax_output', 'credit' => $sale->tax_total];

        $cogs = BigDecimal::zero();
        foreach ($sale->items()->where('tracks_stock', true)->get() as $item) {
            $cogs = $cogs->plus($item->cost_total);
        }
        $lines[] = ['account' => 'cogs', 'debit' => (string) $cogs];
        $lines[] = ['account' => 'inventory', 'credit' => (string) $cogs];

        return $this->journal->post($date, 'Sale '.$sale->reference_no, $lines, 'sale', $sale->id, 'auto', $sale->branch_id);
    }

    public function voidSale(Sale $sale, string $date): ?JournalEntry
    {
        return $this->journal->reverseSource('sale', $sale->id, $date, 'Void of sale '.$sale->reference_no);
    }

    /**
     * Customer return. `$refund` includes tax; `$tax` is the tax part of it; `$trackedCost` is the cost of the
     * stocked items that went back to the shelf. `$method` is 'account' or a tender method.
     */
    public function saleReturn(string $referenceNo, int $returnId, ?int $branchId, string $method, string $refund, string $tax, string $trackedCost, string $date): ?JournalEntry
    {
        $credit = $method === 'account' ? 'receivable' : $this->moneyAccount($method);

        return $this->journal->post($date, 'Sales return '.$referenceNo, [
            ['account' => 'sales_returns', 'debit' => (string) BigDecimal::of($refund)->minus($tax)],
            ['account' => 'tax_output', 'debit' => $tax],
            ['account' => $credit, 'credit' => $refund],
            ['account' => 'inventory', 'debit' => $trackedCost],
            ['account' => 'cogs', 'credit' => $trackedCost],
        ], 'sale_return', $returnId, 'auto', $branchId);
    }

    /** Goods received: stock at net cost, recoverable tax, and the supplier owed the full amount. */
    public function purchaseReceived(string $referenceNo, int $purchaseId, int $warehouseId, string $stockCost, string $total, string $date): ?JournalEntry
    {
        return $this->journal->post($date, 'Purchase '.$referenceNo, [
            ['account' => 'inventory', 'debit' => $stockCost],
            ['account' => 'tax_input', 'debit' => (string) BigDecimal::of($total)->minus($stockCost)],
            ['account' => 'payable', 'credit' => $total],
        ], 'purchase', $purchaseId, 'auto', $this->branchOf($warehouseId));
    }

    /**
     * Goods sent back. The supplier credit is `$credit`; stock leaves at its carrying cost `$inventoryCost`; `$tax` is
     * the recoverable tax handed back. Any difference between credit and carrying cost is a variance.
     */
    public function purchaseReturn(string $referenceNo, int $returnId, int $warehouseId, string $credit, string $inventoryCost, string $tax, string $date): ?JournalEntry
    {
        $variance = BigDecimal::of($credit)->minus($tax)->minus($inventoryCost); // > 0: credit beat the carrying cost

        return $this->journal->post($date, 'Purchase return '.$referenceNo, [
            ['account' => 'payable', 'debit' => $credit],
            ['account' => 'inventory', 'credit' => $inventoryCost],
            ['account' => 'tax_input', 'credit' => $tax],
            ['account' => 'inventory_adjustment', 'credit' => $variance->isPositive() ? (string) $variance : '0', 'debit' => $variance->isNegative() ? (string) $variance->negated() : '0'],
        ], 'purchase_return', $returnId, 'auto', $this->branchOf($warehouseId));
    }

    /** Receipt from a customer (money in, receivable down) or payment to a supplier (money out, payable down). */
    public function partyPayment(string $partyType, string $referenceNo, int $paymentId, string $method, string $amount, string $date): ?JournalEntry
    {
        $money = $this->moneyAccount($method);
        $lines = $partyType === 'customer'
            ? [['account' => $money, 'debit' => $amount], ['account' => 'receivable', 'credit' => $amount]]
            : [['account' => 'payable', 'debit' => $amount], ['account' => $money, 'credit' => $amount]];

        return $this->journal->post($date, ($partyType === 'customer' ? 'Receipt ' : 'Payment ').$referenceNo, $lines, 'party_payment', $paymentId, 'auto');
    }

    /** Opening balance of a customer (+ owes us) or supplier (+ we owe), against opening balance equity. */
    public function partyOpening(string $partyType, string $partyName, int $partyId, string $signedAmount, string $date): ?JournalEntry
    {
        return $this->journal->post($date, 'Opening balance of '.$partyName, $this->partySide($partyType, $signedAmount, 'opening_equity'), $partyType.'_opening', $partyId, 'auto');
    }

    /** Manual correction of a party balance; the other side is the ledger adjustment expense. */
    public function partyAdjustment(string $partyType, string $partyName, int $ledgerEntryId, string $signedAmount, string $date): ?JournalEntry
    {
        return $this->journal->post($date, 'Balance adjustment of '.$partyName, $this->partySide($partyType, $signedAmount, 'ledger_adjustment'), $partyType.'_adjustment', $ledgerEntryId, 'auto');
    }

    /**
     * Stock value change that is not a sale or purchase. `$signedValue` > 0 adds stock value. Opening stock goes
     * against opening balance equity, everything else (damage, loss, count differences) against inventory adjustments.
     */
    public function stockAdjustment(string $sourceType, int $sourceId, string $referenceNo, int $warehouseId, string $signedValue, bool $opening, string $date): ?JournalEntry
    {
        $value = BigDecimal::of($signedValue);
        $other = $opening ? 'opening_equity' : 'inventory_adjustment';
        $abs = (string) $value->abs();

        return $this->journal->post($date, ($opening ? 'Opening stock ' : 'Stock adjustment ').$referenceNo, $value->isPositive()
            ? [['account' => 'inventory', 'debit' => $abs], ['account' => $other, 'credit' => $abs]]
            : [['account' => $other, 'debit' => $abs], ['account' => 'inventory', 'credit' => $abs]],
            $sourceType, $sourceId, 'auto', $this->branchOf($warehouseId));
    }

    /** Approved expense: the category's expense account against cash or bank. */
    public function expense(string $referenceNo, int $expenseId, int $expenseAccountId, string $method, string $amount, string $date, ?int $branchId, ?string $description): ?JournalEntry
    {
        return $this->journal->post($date, 'Expense '.$referenceNo.($description ? ' · '.$description : ''), [
            ['account' => $expenseAccountId, 'debit' => $amount],
            ['account' => $this->moneyAccount($method), 'credit' => $amount],
        ], 'expense', $expenseId, 'auto', $branchId);
    }

    public function voidExpense(string $referenceNo, int $expenseId, string $date): ?JournalEntry
    {
        return $this->journal->reverseSource('expense', $expenseId, $date, 'Void of expense '.$referenceNo);
    }

    /** Cash moved in or out of a till against the owner or the bank (pay-ins and pay-outs). */
    public function cashTransfer(string $direction, string $counter, string $amount, string $referenceNo, int $sessionId, string $date, ?int $branchId, ?string $notes): ?JournalEntry
    {
        $other = $counter === 'bank' ? 'bank' : 'equity';
        $lines = $direction === 'in'
            ? [['account' => 'cash', 'debit' => $amount], ['account' => $other, 'credit' => $amount]]
            : [['account' => $other, 'debit' => $amount], ['account' => 'cash', 'credit' => $amount]];

        return $this->journal->post($date, ($direction === 'in' ? 'Cash in ' : 'Cash out ').$referenceNo.' ('.$counter.')'.($notes ? ' · '.$notes : ''), $lines, 'cash_session', $sessionId, 'auto', $branchId);
    }

    /** Closing difference: counted minus expected. Short (negative) is an expense, over (positive) a gain. */
    public function cashDifference(string $referenceNo, int $sessionId, string $difference, string $date, ?int $branchId): ?JournalEntry
    {
        $value = BigDecimal::of($difference);
        $abs = (string) $value->abs();

        return $this->journal->post($date, 'Cash '.($value->isNegative() ? 'short' : 'over').' at close of '.$referenceNo, $value->isNegative()
            ? [['account' => 'cash_difference', 'debit' => $abs], ['account' => 'cash', 'credit' => $abs]]
            : [['account' => 'cash', 'debit' => $abs], ['account' => 'cash_difference', 'credit' => $abs]],
            'cash_difference', $sessionId, 'auto', $branchId);
    }

    private function partySide(string $partyType, string $signedAmount, string $other): array
    {
        $value = BigDecimal::of($signedAmount);
        $abs = (string) $value->abs();
        $control = $partyType === 'customer' ? 'receivable' : 'payable';

        // Customer: + raises receivable (debit). Supplier: + raises payable (credit).
        $controlDebit = $partyType === 'customer' ? $value->isPositive() : $value->isNegative();

        return $controlDebit
            ? [['account' => $control, 'debit' => $abs], ['account' => $other, 'credit' => $abs]]
            : [['account' => $other, 'debit' => $abs], ['account' => $control, 'credit' => $abs]];
    }

    private function branchOf(int $warehouseId): ?int
    {
        return Warehouse::query()->whereKey($warehouseId)->value('branch_id');
    }
}
