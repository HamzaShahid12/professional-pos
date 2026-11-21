<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BranchAccessAndPermissionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_cashier_only_sees_assigned_branches(): void
    {
        [$biz, $owner] = $this->makeBusiness();
        $main = Branch::withoutGlobalScopes()->where('business_id', $biz->id)->where('is_main', true)->first();
        $other = $this->makeBranch($biz, 'OTH');
        $cashier = $this->makeStaff($biz, 'Cashier', [$main]);

        $ids = $cashier->accessibleBranchIds($biz->id);
        $this->assertTrue($ids->contains($main->id));
        $this->assertFalse($ids->contains($other->id));

        $api = collect($this->actingAs($cashier, 'sanctum')->getJson('/api/v1/branches')->json('data'))->pluck('id');
        $this->assertEquals([$main->id], $api->all());
    }

    public function test_cashier_cannot_switch_to_unassigned_branch(): void
    {
        [$biz] = $this->makeBusiness();
        $main = Branch::withoutGlobalScopes()->where('business_id', $biz->id)->where('is_main', true)->first();
        $other = $this->makeBranch($biz, 'OTH');
        $cashier = $this->makeStaff($biz, 'Cashier', [$main]);

        $this->actingAs($cashier)->post('/context', ['branch_id' => $other->id])->assertForbidden();
    }

    public function test_forged_branch_header_falls_back_to_allowed_branch(): void
    {
        [$biz] = $this->makeBusiness();
        $main = Branch::withoutGlobalScopes()->where('business_id', $biz->id)->where('is_main', true)->first();
        $other = $this->makeBranch($biz, 'OTH');
        $cashier = $this->makeStaff($biz, 'Cashier', [$main]);

        $this->actingAs($cashier, 'sanctum')->withHeader('X-Branch-Id', (string) $other->id)
            ->getJson('/api/v1/auth/me')->assertJsonPath('branch_id', $main->id);
    }

    public function test_owner_and_admin_see_all_branches(): void
    {
        [$biz, $owner] = $this->makeBusiness();
        $this->makeBranch($biz, 'B2');
        $admin = $this->makeStaff($biz, 'Admin');

        $this->assertCount(2, $owner->accessibleBranchIds($biz->id));
        $this->assertCount(2, $admin->accessibleBranchIds($biz->id));
    }

    public function test_cashier_cannot_create_branch_via_api(): void
    {
        [$biz] = $this->makeBusiness();
        $cashier = $this->makeStaff($biz, 'Cashier');

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/branches', ['name' => 'Nope', 'code' => 'NOPE'])->assertForbidden();
    }

    public function test_admin_can_create_and_update_branch(): void
    {
        [$biz] = $this->makeBusiness();
        $admin = $this->makeStaff($biz, 'Admin');

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/branches', ['name' => 'East', 'code' => 'east'])
            ->assertCreated()->assertJsonPath('data.code', 'EAST')->json('data.id');
        $this->putJson("/api/v1/branches/$id", ['name' => 'East 2', 'code' => 'EAST'])->assertOk()->assertJsonPath('data.name', 'East 2');
    }

    public function test_branch_code_is_unique_per_business_only(): void
    {
        [$a, $ownerA] = $this->makeBusiness('Shop A');
        $this->makeBusiness('Shop B'); // also has MAIN

        $this->actingAs($ownerA, 'sanctum')->postJson('/api/v1/branches', ['name' => 'Dup', 'code' => 'MAIN'])->assertStatus(422);
        $this->postJson('/api/v1/branches', ['name' => 'Fine', 'code' => 'NEW'])->assertCreated();
    }

    public function test_only_one_main_branch_and_main_cannot_be_deleted_or_deactivated(): void
    {
        [$biz, $owner] = $this->makeBusiness();
        $this->actingAs($owner, 'sanctum');

        $id = $this->postJson('/api/v1/branches', ['name' => 'New Main', 'code' => 'NM', 'is_main' => true])->json('data.id');
        $this->assertSame(1, Branch::withoutGlobalScopes()->where('business_id', $biz->id)->where('is_main', true)->count());
        $this->putJson("/api/v1/branches/$id", ['name' => 'New Main', 'code' => 'NM', 'is_active' => false])->assertStatus(422);
        $this->actingAs($owner)->delete('/settings/branches/'.$id)->assertSessionHasErrors('branch');
    }

    public function test_web_pages_enforce_backend_permissions(): void
    {
        [$biz] = $this->makeBusiness();
        $cashier = $this->makeStaff($biz, 'Cashier');
        $this->actingAs($cashier);

        foreach (['/users', '/roles', '/settings/branches', '/settings/currencies', '/settings/business', '/activity-logs', '/businesses'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/dashboard')->assertOk();
    }

    public function test_business_owner_can_open_admin_pages_but_not_platform_businesses(): void
    {
        [, $owner] = $this->makeBusiness();
        $this->actingAs($owner);

        foreach (['/users', '/roles', '/settings/branches', '/settings/currencies', '/settings/business', '/activity-logs'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/businesses')->assertForbidden();
    }

    public function test_super_admin_can_open_platform_businesses_page(): void
    {
        [$biz] = $this->makeBusiness();
        $super = $this->makeStaff($biz, 'Super Admin');

        $this->actingAs($super)->get('/businesses')->assertOk();
    }

    public function test_admin_cannot_grant_super_admin_role(): void
    {
        [$biz] = $this->makeBusiness();
        $admin = $this->makeStaff($biz, 'Admin');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@test.com', 'password' => 'password1', 'roles' => ['Super Admin'],
        ])->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@test.com']);
    }

    public function test_user_creation_attaches_to_business_and_only_its_branches(): void
    {
        [$biz, $owner] = $this->makeBusiness();
        [$other] = $this->makeBusiness('Shop B');
        $foreign = $this->makeBranch($other, 'FB');
        $main = Branch::withoutGlobalScopes()->where('business_id', $biz->id)->where('is_main', true)->first();

        $this->actingAs($owner)->post('/users', [
            'name' => 'New Cashier', 'email' => 'newcashier@test.com', 'password' => 'password1',
            'roles' => ['Cashier'], 'branch_ids' => [$foreign->id],
        ])->assertSessionHasErrors('branch_ids.0');

        $this->post('/users', [
            'name' => 'New Cashier', 'email' => 'newcashier@test.com', 'password' => 'password1',
            'roles' => ['Cashier'], 'branch_ids' => [$main->id],
        ])->assertSessionHasNoErrors();

        $user = \App\Models\User::where('email', 'newcashier@test.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Cashier'));
        $this->assertTrue($user->businesses()->where('businesses.id', $biz->id)->exists());
        $this->assertEquals([$main->id], $user->branches()->pluck('branches.id')->all());
    }

    public function test_deactivated_user_is_blocked_on_existing_session_and_api(): void
    {
        [$biz] = $this->makeBusiness();
        $cashier = $this->makeStaff($biz, 'Cashier');
        $this->actingAs($cashier);
        $cashier->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_system_roles_cannot_be_deleted_and_super_admin_cannot_be_edited(): void
    {
        [, $owner] = $this->makeBusiness();
        $this->actingAs($owner);
        $cashierRole = \Spatie\Permission\Models\Role::findByName('Cashier');
        $superRole = \Spatie\Permission\Models\Role::findByName('Super Admin');

        $this->delete("/roles/{$cashierRole->id}")->assertForbidden();
        $this->put("/roles/{$superRole->id}", ['permissions' => []])->assertForbidden();
    }

    public function test_custom_role_permission_editing(): void
    {
        [$biz] = $this->makeBusiness();
        $super = $this->makeStaff($biz, 'Super Admin');
        $this->actingAs($super);

        $this->post('/roles', ['name' => 'Auditor', 'permissions' => ['reports.view']])->assertSessionHasNoErrors();
        $role = \Spatie\Permission\Models\Role::findByName('Auditor');
        $this->assertTrue($role->hasPermissionTo('reports.view'));

        $this->put("/roles/{$role->id}", ['permissions' => ['reports.view', 'accounting.view']])->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->hasPermissionTo('accounting.view'));
        $this->post('/roles', ['name' => 'Bad', 'permissions' => ['made.up']])->assertSessionHasErrors('permissions.0');
    }
}
