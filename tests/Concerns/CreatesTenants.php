<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Services\Business\BusinessService;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;

trait CreatesTenants
{
    protected function seedPermissions(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();
    }

    /** Creates a business (base PKR, main branch MAIN) with its owner. */
    protected function makeBusiness(string $name = 'Shop A', string $ownerEmail = null): array
    {
        $owner = User::create([
            'name' => $name.' Owner',
            'email' => $ownerEmail ?? strtolower(str_replace(' ', '', $name)).'-owner@test.com',
            'password' => 'password1',
        ]);
        $owner->assignRole('Business Owner');
        $business = app(BusinessService::class)->create(['name' => $name, 'base_currency_code' => 'PKR', 'timezone' => 'Asia/Karachi'], $owner);
        $owner->update(['current_business_id' => $business->id]);

        return [$business, $owner];
    }

    protected function makeBranch(Business $business, string $code): Branch
    {
        return app(TenantContext::class)->runAs($business->id, fn () => Branch::create(['name' => "Branch $code", 'code' => $code]));
    }

    /** Staff user in the business with a role and optional branch assignments. */
    protected function makeStaff(Business $business, string $role, array $branches = [], string $email = null): User
    {
        $user = User::create([
            'name' => $role,
            'email' => $email ?? strtolower(str_replace(' ', '', $role)).$business->id.'@test.com',
            'password' => 'password1',
            'current_business_id' => $business->id,
        ]);
        $user->assignRole($role);
        $user->businesses()->attach($business->id, ['is_owner' => false]);
        $user->branches()->sync(collect($branches)->pluck('id')->all());

        return $user;
    }
}
