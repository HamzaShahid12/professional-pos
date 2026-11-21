<?php

namespace App\Services\Parties;

use App\Models\ActivityLog;
use App\Services\DocumentNumberService;
use App\Services\Accounting\PostingService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Create / update / archive customers and suppliers. Balances are never edited here: they come from the ledger. */
class PartyService
{
    private const PREFIX = ['customer' => 'CUS', 'supplier' => 'SUP'];

    public function __construct(private LedgerService $ledger, private DocumentNumberService $numbers, private PostingService $posting) {}

    /**
     * `opening_balance` is signed in ledger direction: positive = they owe us (customer) / we owe them (supplier).
     */
    public function create(string $type, array $data): Model
    {
        $class = LedgerService::modelFor($type);

        return DB::transaction(function () use ($class, $type, $data) {
            $attrs = collect($data)->except(['opening_balance', 'balance'])->all();
            if (blank($attrs['code'] ?? null)) {
                $attrs['code'] = $this->numbers->next($type, self::PREFIX[$type]);
            }

            $party = $class::create($attrs);

            $opening = (string) ($data['opening_balance'] ?? '0');
            if (! BigDecimal::of($opening)->isZero()) {
                $date = $this->posting->today();
                $this->ledger->post($party, 'opening', $opening, $date, 'Opening balance');
                $this->posting->partyOpening($type, $party->name, $party->id, $opening, $date);
            }

            ActivityLog::record("$type.created", $party, ['code' => $party->code]);

            return $party->refresh();
        });
    }

    public function update(Model $party, array $data): Model
    {
        $party->update(collect($data)->except(['opening_balance', 'balance', 'code'])->all());
        ActivityLog::record($party::PARTY_TYPE.'.updated', $party, ['code' => $party->code]);

        return $party->refresh();
    }

    /** Archive (soft delete). Blocked while money is outstanding either way. */
    public function archive(Model $party): void
    {
        if (! BigDecimal::of($party->balance)->isZero()) {
            throw ValidationException::withMessages(['party' => 'This account has an outstanding balance. Settle or adjust it to zero before archiving.']);
        }

        $party->delete();
        ActivityLog::record($party::PARTY_TYPE.'.archived', $party, ['code' => $party->code]);
    }
}
