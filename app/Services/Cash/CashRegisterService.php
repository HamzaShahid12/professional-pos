<?php

namespace App\Services\Cash;

use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\DocumentNumberService;
use App\Services\Settings\SettingsService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tills and shifts. A cashier opens a session on a register with a float; cash sales, cash refunds and cash
 * expenses land on that cashier's open session automatically; pay-ins and pay-outs are recorded by hand. Closing
 * compares the counted cash with what the ledger says should be there and books the difference.
 */
class CashRegisterService
{
    public const MOVEMENT_TYPES = ['sale', 'refund', 'expense', 'pay_in', 'pay_out'];

    public function __construct(private DocumentNumberService $numbers, private PostingService $posting, private SettingsService $settings) {}

    public function openSession(CashRegister $register, User $user, string $openingFloat): CashSession
    {
        return DB::transaction(function () use ($register, $user, $openingFloat) {
            $register = CashRegister::query()->whereKey($register->id)->lockForUpdate()->firstOrFail();
            if (! $register->is_active) {
                throw ValidationException::withMessages(['register' => 'This register is switched off.']);
            }
            if (! $user->accessibleBranchIds($register->business_id)->contains($register->branch_id)) {
                throw ValidationException::withMessages(['register' => 'You do not work in this register\'s branch.']);
            }
            if ($register->sessions()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['register' => 'This register already has an open session. Close it first.']);
            }
            if (CashSession::query()->where('user_id', $user->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['register' => 'You already have an open session. Close it before opening another.']);
            }

            $session = CashSession::create([
                'cash_register_id' => $register->id, 'user_id' => $user->id, 'reference_no' => $this->numbers->next('cash_session', 'SES'),
                'status' => 'open', 'opening_float' => (string) BigDecimal::of($openingFloat)->toScale(4), 'opened_at' => now(),
            ]);
            ActivityLog::record('cash_session.opened', $session, ['reference_no' => $session->reference_no, 'register' => $register->name]);

            return $session;
        });
    }

    public function openFor(User $user): ?CashSession
    {
        return CashSession::query()->where('user_id', $user->id)->where('status', 'open')->first();
    }

    /** When the business insists on it, a cash sale needs an open session. */
    public function assertCanTakeCash(User $user): void
    {
        if ($this->settings->get('sales.require_open_register') && ! $this->openFor($user)) {
            throw ValidationException::withMessages(['payments' => 'Open your cash register before taking cash.']);
        }
    }

    /**
     * Put cash on the user's open session. Returns null (and records nothing) when the user has no open session:
     * the books are still right, the till just has no shift to attribute the cash to.
     */
    public function record(User $user, string $type, string $signedAmount, ?string $refType = null, ?int $refId = null, ?string $notes = null, ?string $counter = null): ?CashMovement
    {
        if (BigDecimal::of($signedAmount)->isZero()) {
            return null;
        }
        $session = CashSession::query()->where('user_id', $user->id)->where('status', 'open')->lockForUpdate()->first();
        if (! $session) {
            return null;
        }

        return CashMovement::create([
            'cash_session_id' => $session->id, 'type' => $type, 'amount' => (string) BigDecimal::of($signedAmount)->toScale(4), 'counter' => $counter,
            'reference_type' => $refType, 'reference_id' => $refId, 'notes' => $notes, 'created_by' => $user->id,
        ]);
    }

    /** Cash put in or taken out by hand, against the owner or the bank. */
    public function payInOut(CashSession $session, User $user, string $direction, string $counter, string $amount, string $notes): CashMovement
    {
        return DB::transaction(function () use ($session, $user, $direction, $counter, $amount, $notes) {
            $session = $this->lockOpen($session, $user);
            $value = BigDecimal::of($amount)->toScale(4);
            if (! $value->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
            }
            if ($direction === 'out' && $value->isGreaterThan($this->expected($session))) {
                throw ValidationException::withMessages(['amount' => 'There is only '.$this->expected($session)->toScale(2).' in this drawer.']);
            }

            $movement = CashMovement::create([
                'cash_session_id' => $session->id, 'type' => $direction === 'in' ? 'pay_in' : 'pay_out',
                'amount' => (string) ($direction === 'in' ? $value : $value->negated()), 'counter' => $counter, 'notes' => $notes, 'created_by' => $user->id,
            ]);
            $this->posting->cashTransfer($direction, $counter, (string) $value, $session->reference_no, $session->id, $this->posting->today(), $session->register->branch_id, $notes);
            ActivityLog::record('cash_session.'.$movement->type, $session, ['amount' => (string) $value, 'counter' => $counter]);

            return $movement;
        });
    }

    /** Count the drawer and close the shift. The difference (counted - expected) is booked to cash over/short. */
    public function close(CashSession $session, User $user, string $counted, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($session, $user, $counted, $notes) {
            $session = $this->lockOpen($session, $user);
            $count = BigDecimal::of($counted)->toScale(4);
            if ($count->isNegative()) {
                throw ValidationException::withMessages(['counted_cash' => 'The counted cash cannot be negative.']);
            }

            $expected = $this->expected($session);
            $difference = $count->minus($expected);
            if (! $difference->isZero()) {
                $this->posting->cashDifference($session->reference_no, $session->id, (string) $difference->toScale(4), $this->posting->today(), $session->register->branch_id);
            }

            $session->update([
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id, 'expected_cash' => (string) $expected->toScale(4),
                'counted_cash' => (string) $count, 'difference' => (string) $difference->toScale(4), 'notes' => $notes,
            ]);
            ActivityLog::record('cash_session.closed', $session, ['reference_no' => $session->reference_no, 'difference' => (string) $difference]);

            return $session->load('register');
        });
    }

    /** Float plus every movement so far. */
    public function expected(CashSession $session): BigDecimal
    {
        $total = BigDecimal::of($session->opening_float);
        foreach (CashMovement::query()->where('cash_session_id', $session->id)->pluck('amount') as $amount) {
            $total = $total->plus($amount);
        }

        return $total->toScale(4);
    }

    /** @return array{float:string, by_type:array<string,string>, expected:string} */
    public function summary(CashSession $session): array
    {
        $by = array_fill_keys(self::MOVEMENT_TYPES, BigDecimal::zero());
        foreach (CashMovement::query()->where('cash_session_id', $session->id)->get(['type', 'amount']) as $m) {
            $by[$m->type] = $by[$m->type]->plus($m->amount);
        }

        return [
            'float' => (string) BigDecimal::of($session->opening_float)->toScale(4),
            'by_type' => array_map(fn ($v) => (string) $v->toScale(4), $by),
            'expected' => (string) $this->expected($session),
        ];
    }

    /** Only the cashier who owns the till, or someone with access to every branch, may work on a session. */
    public function canOperate(CashSession $session, User $user): bool
    {
        return $session->user_id === $user->id || $user->hasFullBranchAccess($session->business_id);
    }

    private function lockOpen(CashSession $session, User $user): CashSession
    {
        $locked = CashSession::query()->with('register')->whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'open') {
            throw ValidationException::withMessages(['session' => 'This session is already closed.']);
        }
        if (! $this->canOperate($locked, $user)) {
            throw ValidationException::withMessages(['session' => 'This session belongs to another cashier.']);
        }

        return $locked;
    }
}
