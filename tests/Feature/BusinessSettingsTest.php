<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_owner_updates_business_profile_and_settings(): void
    {
        [$biz, $owner] = $this->makeBusiness();

        $this->actingAs($owner)->post('/settings/business', [
            'name' => 'Renamed Shop', 'timezone' => 'Asia/Karachi', 'email' => 'hello@shop.test',
            'settings' => ['inventory' => ['allow_negative_stock' => true, 'costing_method' => 'fifo'], 'sales' => ['invoice_prefix' => 'SH', 'receipt_width' => '58']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed Shop', $biz->fresh()->name);
        app(TenantContext::class)->runAs($biz->id, function () {
            $s = app(SettingsService::class);
            $this->assertTrue($s->get('inventory.allow_negative_stock'));
            $this->assertSame('fifo', $s->get('inventory.costing_method'));
            $this->assertSame('58', $s->get('sales.receipt_width'));
        });
    }

    public function test_base_currency_cannot_be_changed_after_creation(): void
    {
        [$biz, $owner] = $this->makeBusiness();

        $this->actingAs($owner)->post('/settings/business', ['name' => 'X', 'timezone' => 'UTC', 'base_currency_code' => 'USD'])
            ->assertSessionHasErrors('base_currency_code');
        $this->assertSame('PKR', $biz->fresh()->base_currency_code);
    }

    public function test_invalid_setting_values_are_rejected(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->actingAs($owner)->post('/settings/business', [
            'name' => 'X', 'timezone' => 'UTC', 'settings' => ['inventory' => ['costing_method' => 'lifo']],
        ])->assertSessionHasErrors('settings.inventory.costing_method');
    }

    public function test_super_admin_creates_business_via_api_and_is_attached_as_owner(): void
    {
        [$biz] = $this->makeBusiness();
        $super = $this->makeStaff($biz, 'Super Admin');

        $id = $this->actingAs($super, 'sanctum')->postJson('/api/v1/businesses', [
            'name' => 'Brand New', 'timezone' => 'UTC', 'base_currency_code' => 'USD',
        ])->assertOk()->json('data.id');

        $this->assertDatabaseHas('business_user', ['business_id' => $id, 'user_id' => $super->id, 'is_owner' => true]);
        $this->assertDatabaseHas('currencies', ['business_id' => $id, 'code' => 'USD', 'is_base' => true]);
    }

    public function test_non_super_admin_cannot_create_a_business(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/businesses', ['name' => 'Nope', 'timezone' => 'UTC', 'base_currency_code' => 'USD'])->assertForbidden();
    }

    public function test_dashboard_shows_real_counts_for_current_business_only(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        $this->makeBusiness('Shop B');
        $this->makeBranch($a, 'EXTRA');

        $this->actingAs($ownerA)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/Index')->where('stats.branches', 2)->where('stats.users', 1)->where('stats.currencies', 1));
    }

    public function test_activity_log_records_business_scoped_events(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        [$b] = $this->makeBusiness('Shop B');

        $this->actingAs($ownerA, 'sanctum')->postJson('/api/v1/branches', ['name' => 'N', 'code' => 'N1'])->assertCreated();

        $this->assertDatabaseHas('activity_logs', ['business_id' => $a->id, 'action' => 'branch.created']);
        $this->assertDatabaseMissing('activity_logs', ['business_id' => $b->id, 'action' => 'branch.created']);
    }
}
