<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\CurrencyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;
    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        app(TenantContext::class)->set($this->business->id);
    }

    private function usd(string $rate = '280'): Currency
    {
        $c = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'exchange_rate' => 1, 'is_active' => true]);
        app(CurrencyService::class)->setRate($c, $rate, Carbon::parse('2026-01-01'), $this->owner, 'initial');

        return $c->refresh();
    }

    public function test_converts_between_currencies_through_base(): void
    {
        $service = app(CurrencyService::class);
        $usd = $this->usd();
        $pkr = Currency::where('is_base', true)->first();

        $this->assertSame('28000.00', $service->convert('100', $usd, $pkr));
        $this->assertSame('1.00', $service->convert('280', $pkr, $usd));
        $this->assertSame('0.36', $service->convert('100', $pkr, $usd)); // 0.357142… rounds half up
    }

    public function test_historical_rates_are_preserved_when_rate_changes(): void
    {
        $service = app(CurrencyService::class);
        $usd = $this->usd('280');
        $service->setRate($usd, '290', Carbon::parse('2026-06-01'), $this->owner);
        $service->setRate($usd->refresh(), '300', Carbon::parse('2026-09-01'), $this->owner);

        $usd->refresh();
        $this->assertSame('300', rtrim(rtrim((string) $usd->exchange_rate, '0'), '.'));
        $this->assertSame(3, ExchangeRate::where('currency_id', $usd->id)->count());

        $this->assertSame(280.0, (float) $service->rateAt($usd, Carbon::parse('2026-03-01')));
        $this->assertSame(290.0, (float) $service->rateAt($usd, Carbon::parse('2026-07-01')));
        $this->assertSame(300.0, (float) $service->rateAt($usd, Carbon::parse('2026-10-01')));

        $pkr = Currency::where('is_base', true)->first();
        $this->assertSame('29000.00', $service->convert('100', $usd, $pkr, Carbon::parse('2026-07-01')));
    }

    public function test_backdated_rate_does_not_overwrite_the_current_rate(): void
    {
        $service = app(CurrencyService::class);
        $usd = $this->usd('280');
        $service->setRate($usd, '290', Carbon::parse('2026-06-01'), $this->owner);
        $service->setRate($usd->refresh(), '270', Carbon::parse('2026-02-01'), $this->owner); // older than current

        $this->assertSame(290.0, (float) $usd->refresh()->exchange_rate);
    }

    public function test_exchange_rate_history_is_immutable(): void
    {
        $usd = $this->usd();
        $row = ExchangeRate::where('currency_id', $usd->id)->firstOrFail();

        $this->expectException(\LogicException::class);
        $row->update(['rate' => '1']);
    }

    public function test_exchange_rate_history_cannot_be_deleted(): void
    {
        $usd = $this->usd();
        $this->expectException(\LogicException::class);
        ExchangeRate::where('currency_id', $usd->id)->firstOrFail()->delete();
    }

    public function test_base_currency_rate_is_fixed_and_rate_must_be_positive(): void
    {
        $service = app(CurrencyService::class);
        $pkr = Currency::where('is_base', true)->first();

        try {
            $service->setRate($pkr, '2');
            $this->fail('Base rate change should be rejected');
        } catch (ValidationException) {
            $this->assertSame('1', (string) $service->rateAt($pkr));
        }

        $this->expectException(ValidationException::class);
        $service->setRate($this->usd(), '0');
    }

    public function test_money_never_uses_floats_for_precision(): void
    {
        $service = app(CurrencyService::class);
        $usd = $this->usd('280.12345678');
        $pkr = Currency::where('is_base', true)->first();

        $this->assertSame('28012.35', $service->convert('100', $usd, $pkr));
        $this->assertSame('Rs 1,250.50', $service->format('1250.5', $pkr));
    }

    public function test_api_currency_lifecycle_keeps_history(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $id = $this->postJson('/api/v1/currencies', [
            'code' => 'eur', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'exchange_rate' => 305,
        ])->assertCreated()->assertJsonPath('data.code', 'EUR')->json('data.id');

        $this->postJson("/api/v1/currencies/$id/rates", ['rate' => 310])->assertOk()->assertJsonPath('data.exchange_rate', '310.00000000');
        $this->assertSame(2, ExchangeRate::where('currency_id', $id)->count());

        // Code and rate cannot be changed through the plain update endpoint.
        $this->putJson("/api/v1/currencies/$id", ['code' => 'XXX', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2])->assertStatus(422);
        $this->putJson("/api/v1/currencies/$id", ['name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'exchange_rate' => 1])->assertStatus(422);
        $this->putJson("/api/v1/currencies/$id", ['name' => 'Euro (EU)', 'symbol' => '€', 'decimal_places' => 2])->assertOk();
    }

    public function test_duplicate_currency_code_rejected_in_same_business_only(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $payload = ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'exchange_rate' => 280];

        $this->postJson('/api/v1/currencies', $payload)->assertCreated();
        $this->postJson('/api/v1/currencies', $payload)->assertStatus(422)->assertJsonValidationErrors('code');

        [, $ownerB] = $this->makeBusiness('Shop B');
        $this->actingAs($ownerB, 'sanctum')->postJson('/api/v1/currencies', $payload)->assertCreated();
    }

    public function test_cashier_cannot_manage_currencies(): void
    {
        $cashier = $this->makeStaff($this->business, 'Cashier');
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/currencies', ['code' => 'USD', 'name' => 'x', 'symbol' => '$', 'decimal_places' => 2, 'exchange_rate' => 1])
            ->assertForbidden();
        $this->getJson('/api/v1/currencies')->assertOk(); // but can read them for display
    }
}
