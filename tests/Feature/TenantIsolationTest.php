<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Currency;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_models_fail_closed_without_a_business_context(): void
    {
        $this->makeBusiness('Shop A');
        app(TenantContext::class)->forget();

        $this->assertSame(0, Branch::count());
        $this->assertSame(0, Currency::count());
    }

    public function test_creating_a_scoped_record_without_context_throws(): void
    {
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->forget();
        Branch::create(['name' => 'Orphan', 'code' => 'X']);
    }

    public function test_business_a_cannot_read_business_b_branches_or_currencies(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');
        $branchB = $this->makeBranch($b, 'BB1');
        $currencyB = app(TenantContext::class)->runAs($b->id, fn () => Currency::first());

        $this->actingAs($ownerA, 'sanctum');

        $ids = collect($this->getJson('/api/v1/branches')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($branchB->id));

        $this->getJson("/api/v1/branches/{$branchB->id}")->assertNotFound();
        $this->putJson("/api/v1/branches/{$branchB->id}", ['name' => 'Hacked', 'code' => 'HK'])->assertNotFound();
        $this->postJson("/api/v1/currencies/{$currencyB->id}/rates", ['rate' => 5])->assertNotFound();
    }

    public function test_user_cannot_view_or_update_another_business(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');

        $this->actingAs($ownerA, 'sanctum');
        $this->getJson("/api/v1/businesses/{$b->id}")->assertForbidden();
        $this->putJson("/api/v1/businesses/{$b->id}", ['name' => 'Stolen', 'timezone' => 'UTC'])->assertForbidden();
        $this->assertSame('Shop B', $b->fresh()->name);
    }

    public function test_business_list_only_contains_own_businesses(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        $this->makeBusiness('Shop B');

        $names = collect($this->actingAs($ownerA, 'sanctum')->getJson('/api/v1/businesses')->json('data'))->pluck('name');
        $this->assertEquals(['Shop A'], $names->all());
    }

    public function test_cannot_switch_context_to_a_business_you_do_not_belong_to(): void
    {
        [, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');

        $this->actingAs($ownerA)->post('/context', ['business_id' => $b->id])->assertForbidden();
    }

    public function test_forged_business_header_is_ignored(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');

        $this->actingAs($ownerA, 'sanctum')->withHeader('X-Business-Id', (string) $b->id)
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('business_id', $a->id);
    }

    public function test_user_list_is_scoped_to_current_business(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');
        $this->makeStaff($a, 'Cashier', [], 'cashier-a@test.com');
        $this->makeStaff($b, 'Cashier', [], 'cashier-b@test.com');

        $this->actingAs($ownerA)->get('/users')->assertOk()->assertSee('cashier-a@test.com', false)->assertDontSee('cashier-b@test.com', false);
    }

    public function test_cannot_edit_a_user_from_another_business(): void
    {
        [, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');
        $victim = $this->makeStaff($b, 'Cashier');

        $this->actingAs($ownerA)->put("/users/{$victim->id}", ['name' => 'Pwned', 'email' => $victim->email])->assertForbidden();
        $this->assertNotSame('Pwned', $victim->fresh()->name);
    }

    public function test_branch_manager_id_must_belong_to_same_business(): void
    {
        [, $ownerA] = $this->makeBusiness('Shop A');
        [$b, $ownerB] = $this->makeBusiness('Shop B');

        $this->actingAs($ownerA, 'sanctum')->postJson('/api/v1/branches', ['name' => 'X', 'code' => 'X1', 'manager_id' => $ownerB->id])
            ->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_business_creation_sets_up_base_currency_main_branch_and_settings(): void
    {
        [$business, $owner] = $this->makeBusiness('Shop A');

        app(TenantContext::class)->runAs($business->id, function () use ($owner) {
            $base = Currency::where('is_base', true)->sole();
            $this->assertSame('PKR', $base->code);
            $this->assertSame('1.00000000', (string) $base->exchange_rate);
            $this->assertSame('MAIN', Branch::where('is_main', true)->sole()->code);
            $this->assertDatabaseHas('business_settings', ['key' => 'inventory.costing_method']);
            $this->assertTrue($owner->branches()->where('code', 'MAIN')->exists());
        });
    }
}
