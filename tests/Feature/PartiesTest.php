<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\PartyPayment;
use App\Models\Supplier;
use App\Services\Parties\LedgerService;
use App\Services\Parties\PartyService;
use App\Services\Parties\PaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PartiesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;

    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        $this->actingAs($this->owner);
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    private function customer(array $o = []): Customer
    {
        static $n = 0;
        $n++;

        return $this->in(fn () => app(PartyService::class)->create('customer', array_merge(['name' => "Customer $n"], $o)));
    }

    private function supplier(array $o = []): Supplier
    {
        static $n = 0;
        $n++;

        return $this->in(fn () => app(PartyService::class)->create('supplier', array_merge(['name' => "Supplier $n"], $o)));
    }

    private function assertBalanceMatchesLedger($party): void
    {
        $sum = $this->in(fn () => PartyLedgerEntry::where('party_type', $party::PARTY_TYPE)->where('party_id', $party->id)->sum('amount'));
        $this->assertSame(0, bccomp((string) $sum, (string) $this->in(fn () => $party->fresh())->balance, 4));
    }

    private function pay(string $type, int $id, string $amount, string $method = 'cash')
    {
        return $this->in(fn () => app(PaymentService::class)->record($type, $id, ['amount' => $amount, 'method' => $method, 'paid_at' => '2026-11-20'], $this->owner->id));
    }

    public function test_codes_are_generated_per_business_and_opening_balance_posts_one_ledger_entry(): void
    {
        $a = $this->customer(['opening_balance' => '1500.50']);
        $b = $this->customer();
        [$other] = $this->makeBusiness('Shop B');
        $c = $this->in(fn () => app(PartyService::class)->create('customer', ['name' => 'Theirs']), $other);

        $this->assertSame('CUS-000001', $a->code);
        $this->assertSame('CUS-000002', $b->code);
        $this->assertSame('CUS-000001', $c->code);
        $this->assertSame('1500.5000', $a->balance);
        $this->assertSame('opening', $this->in(fn () => PartyLedgerEntry::where('party_id', $a->id)->where('party_type', 'customer')->value('entry_type')));
        $this->assertSame(0, $this->in(fn () => PartyLedgerEntry::where('party_id', $b->id)->count()));
        $this->assertBalanceMatchesLedger($a);
    }

    public function test_receipts_reduce_the_balance_and_overpayment_becomes_an_advance(): void
    {
        $c = $this->customer(['opening_balance' => '1000']);
        $p1 = $this->pay('customer', $c->id, '400');
        $this->assertSame('RCP-000001', $p1->reference_no);
        $this->assertSame('600.0000', $this->in(fn () => $c->fresh())->balance);

        $this->pay('customer', $c->id, '700');
        $this->assertSame('-100.0000', $this->in(fn () => $c->fresh())->balance);
        $this->assertSame('0.0000', $this->in(fn () => app(LedgerService::class)->creditAvailable($this->in(fn () => $c->fresh()))));
        $this->assertBalanceMatchesLedger($c);
    }

    public function test_supplier_payments_use_their_own_numbering_and_reduce_the_payable(): void
    {
        $s = $this->supplier(['opening_balance' => '5000']);
        $p = $this->pay('supplier', $s->id, '1250.25', 'bank');

        $this->assertSame('PAY-000001', $p->reference_no);
        $this->assertSame('3749.7500', $this->in(fn () => $s->fresh())->balance);
        $this->assertSame('SUP-000001', $s->code);
        $this->assertBalanceMatchesLedger($s);
    }

    public function test_adjustments_move_the_balance_and_everything_stays_reconciled(): void
    {
        $c = $this->customer(['opening_balance' => '100']);
        $this->in(fn () => app(PaymentService::class)->adjust('customer', $c->id, '25.5', '2026-11-20', 'Late fee'));
        $this->in(fn () => app(PaymentService::class)->adjust('customer', $c->id, '-10', '2026-11-20', 'Goodwill'));
        $this->pay('customer', $c->id, '15.5');

        $this->assertSame('100.0000', $this->in(fn () => $c->fresh())->balance);
        $this->assertBalanceMatchesLedger($c);
    }

    public function test_zero_entries_are_rejected_and_ledger_rows_and_payments_are_immutable(): void
    {
        $c = $this->customer(['opening_balance' => '10']);

        try {
            $this->in(fn () => app(PaymentService::class)->adjust('customer', $c->id, '0', '2026-11-20', 'Nothing'));
            $this->fail('A zero adjustment must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $payment = $this->pay('customer', $c->id, '5');
        $entry = $this->in(fn () => PartyLedgerEntry::where('party_id', $c->id)->first());

        foreach ([fn () => $entry->update(['amount' => 1]), fn () => $entry->delete(), fn () => $payment->update(['amount' => 1]), fn () => $payment->delete()] as $try) {
            try {
                $this->in($try);
                $this->fail('Expected an immutability error.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_archive_is_blocked_while_a_balance_exists(): void
    {
        $c = $this->customer(['opening_balance' => '50']);

        try {
            $this->in(fn () => app(PartyService::class)->archive($c));
            $this->fail('Archive must be blocked.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->pay('customer', $c->id, '50');
        $this->in(fn () => app(PartyService::class)->archive($this->in(fn () => $c->fresh())));
        $this->assertSoftDeleted('customers', ['id' => $c->id]);
    }

    public function test_statement_has_brought_forward_balance_and_closing_balance(): void
    {
        $c = $this->customer();
        $this->in(fn () => app(PaymentService::class)->adjust('customer', $c->id, '50', '2026-10-01', 'Old charge'));
        $this->in(fn () => app(PaymentService::class)->adjust('customer', $c->id, '30', '2026-11-10', 'New charge'));

        $s = $this->in(fn () => app(LedgerService::class)->statement($this->in(fn () => $c->fresh()), '2026-11-01', '2026-11-30'));
        $this->assertSame('50.0000', $s['opening']);
        $this->assertSame('80.0000', $s['closing']);
        $this->assertSame(1, $s['entries']->total());
    }

    public function test_credit_limit_available_amount(): void
    {
        $c = $this->customer(['credit_limit' => '1000', 'opening_balance' => '300']);
        $this->assertSame('700.0000', $this->in(fn () => app(LedgerService::class)->creditAvailable($c)));
    }

    public function test_business_isolation_over_http(): void
    {
        [$other, $otherOwner] = $this->makeBusiness('Shop B');
        $theirs = $this->in(fn () => app(PartyService::class)->create('customer', ['name' => 'Secret']), $other);
        $mine = $this->customer();

        $this->get('/customers/'.$theirs->id)->assertNotFound();
        $this->post('/customers/'.$theirs->id.'/payments', ['amount' => '1', 'method' => 'cash', 'paid_at' => '2026-11-20'])->assertNotFound();
        $this->get('/customers/'.$mine->id)->assertOk();
        $this->get('/customers')->assertOk()->assertDontSee('Secret');
        $this->assertSame('0.0000', $this->in(fn () => $theirs->fresh()->balance, $other));
    }

    public function test_permissions_gate_the_screens(): void
    {
        $storekeeper = $this->makeStaff($this->business, 'Storekeeper');
        $cashier = $this->makeStaff($this->business, 'Cashier');

        $this->actingAs($storekeeper)->get('/customers')->assertForbidden();
        $this->actingAs($storekeeper)->get('/suppliers')->assertForbidden();
        $this->actingAs($cashier)->get('/customers')->assertOk();
        $this->actingAs($cashier)->get('/suppliers')->assertForbidden(); // cashier manages customers only
    }

    public function test_create_pay_and_adjust_through_the_web_forms(): void
    {
        $this->post('/customers', ['name' => 'Walk-in Ali', 'phone' => '0300', 'credit_limit' => '500', 'opening_balance' => '120'])->assertRedirect();
        $c = $this->in(fn () => Customer::where('name', 'Walk-in Ali')->firstOrFail());
        $this->assertSame('120.0000', $c->balance);

        $this->post("/customers/{$c->id}/payments", ['amount' => '20', 'method' => 'cash', 'paid_at' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->post("/customers/{$c->id}/adjustments", ['amount' => '5', 'entry_date' => '2026-11-20', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/customers/{$c->id}/payments", ['amount' => '-5', 'method' => 'cash', 'paid_at' => '2026-11-20'])->assertSessionHasErrors('amount');
        $this->post('/customers', ['name' => 'Dup', 'code' => $c->code])->assertSessionHasErrors('code');

        $this->assertSame('100.0000', $this->in(fn () => $c->fresh())->balance);
        $this->get("/customers/{$c->id}")->assertOk();
    }

    public function test_api_lists_creates_and_receives_payments(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $id = $this->postJson('/api/v1/customers', ['name' => 'API Co', 'credit_limit' => '2500', 'opening_balance' => '200'])
            ->assertCreated()->assertJsonPath('data.balance', '200.0000')->json('data.id');

        $this->getJson('/api/v1/customers?search=API')->assertOk()->assertJsonPath('data.0.name', 'API Co');
        $this->postJson("/api/v1/customers/$id/payments", ['amount' => '50', 'method' => 'bank', 'paid_at' => '2026-11-20'])
            ->assertCreated()->assertJsonPath('data.party_balance', '150.0000');
        $this->postJson('/api/v1/suppliers', ['name' => 'Vendor', 'payment_terms_days' => 30])->assertCreated()->assertJsonPath('data.payment_terms_days', 30);
        $this->postJson('/api/v1/customers', ['phone' => '1'])->assertStatus(422);
    }

    public function test_dashboard_totals_only_count_positive_balances(): void
    {
        $this->customer(['opening_balance' => '300']);
        $this->customer(['opening_balance' => '-50']);
        $this->supplier(['opening_balance' => '900']);

        $totals = $this->in(fn () => app(\App\Services\Dashboard\DashboardService::class)->overview($this->owner))['accounts'];
        $this->assertSame('300.0000', $totals['receivable']);
        $this->assertSame('900.0000', $totals['payable']);
    }
}
