<?php

namespace App\Services\Parties;

use App\Models\ActivityLog;
use App\Models\PartyLedgerEntry;
use App\Models\PartyPayment;
use App\Services\Accounting\PostingService;
use App\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Receipts from customers and payments to suppliers, plus manual ledger adjustments. All immutable once posted. */
class PaymentService
{
    public const METHODS = ['cash', 'bank', 'card', 'cheque', 'other'];

    public function __construct(private LedgerService $ledger, private DocumentNumberService $numbers, private PostingService $posting) {}

    /** Money in from a customer or out to a supplier: always reduces the balance (an overpayment becomes an advance). */
    public function record(string $type, int $partyId, array $data, int $userId): PartyPayment
    {
        $class = LedgerService::modelFor($type);

        return DB::transaction(function () use ($class, $type, $partyId, $data, $userId) {
            $party = $class::query()->findOrFail($partyId);
            $amount = BigDecimal::of((string) $data['amount'])->toScale(4);

            $payment = PartyPayment::create([
                'reference_no' => $this->numbers->next($type.'_payment', $type === 'customer' ? 'RCP' : 'PAY'),
                'party_type' => $type, 'party_id' => $party->id, 'amount' => (string) $amount,
                'method' => $data['method'], 'method_reference' => $data['method_reference'] ?? null,
                'paid_at' => $data['paid_at'], 'notes' => $data['notes'] ?? null, 'created_by' => $userId,
            ]);

            $this->ledger->post(
                $party, 'payment', (string) $amount->negated(), $data['paid_at'],
                ($type === 'customer' ? 'Received' : 'Paid').' '.$payment->reference_no.' ('.$data['method'].')',
                'party_payment', $payment->id,
            );

            $this->posting->partyPayment($type, $payment->reference_no, $payment->id, $data['method'], (string) $amount, $data['paid_at']);
            ActivityLog::record("$type.payment", $payment, ['reference_no' => $payment->reference_no, 'amount' => (string) $amount]);

            return $payment;
        });
    }

    /** Signed manual correction (positive raises the balance, negative lowers it). A reason is mandatory. */
    public function adjust(string $type, int $partyId, string $amount, string $date, string $reason): PartyLedgerEntry
    {
        $class = LedgerService::modelFor($type);

        return DB::transaction(function () use ($class, $type, $partyId, $amount, $date, $reason) {
            $party = $class::query()->findOrFail($partyId);
            $entry = $this->ledger->post($party, 'adjustment', $amount, $date, $reason);
            $this->posting->partyAdjustment($type, $party->name, $entry->id, $amount, $date);
            ActivityLog::record("$type.adjusted", $party, ['amount' => $amount, 'reason' => $reason]);

            return $entry;
        });
    }
}
