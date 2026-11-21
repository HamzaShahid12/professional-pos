<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class Phase10Test extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Routes that are public on purpose. Anything else must require a signed-in user. */
    private const PUBLIC_ROUTES = ['/', 'login', 'logout', 'up', 'api/v1/auth/login', 'forgot-password', 'reset-password/{token}', 'reset-password', 'register'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_every_route_requires_authentication_unless_listed_as_public(): void
    {
        $unguarded = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (in_array($uri, self::PUBLIC_ROUTES, true) || str_starts_with($uri, 'sanctum/') || str_starts_with($uri, 'storage/') || str_starts_with($uri, '_')) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $guarded = collect($mw)->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'guest')));
            if (! $guarded) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }
        $this->assertSame([], $unguarded, 'Routes without an auth middleware: '.implode(', ', $unguarded));
    }

    public function test_guests_are_sent_to_login_and_api_guests_get_401(): void
    {
        foreach (['/dashboard', '/sales', '/reports', '/accounting/accounts', '/expenses'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $this->getJson('/api/v1/reports')->assertUnauthorized();
    }

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/login');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->get('https://localhost/login');
        $secure->assertHeader('Strict-Transport-Security');
    }

    public function test_reports_api_needs_the_permission_and_returns_rows(): void
    {
        [$business, $owner] = $this->makeBusiness();
        $main = \App\Models\Branch::withoutGlobalScopes()->where('business_id', $business->id)->where('is_main', true)->first();
        $cashier = $this->makeStaff($business, 'Cashier', [$main]);

        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/reports')->assertForbidden();

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reports')->assertOk()->assertJsonCount(10, 'data');
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reports/sales_daily?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonStructure(['data' => ['title', 'columns', 'rows', 'totals']]);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reports/nope')->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reports/sales_daily?from=2026-11-30&to=2026-11-01')->assertStatus(422);
    }

    public function test_xlsx_export_downloads_and_keeps_text_as_text(): void
    {
        [$business, $owner] = $this->makeBusiness();
        $this->actingAs($owner)->get('/reports/sales_daily/export?format=xlsx&from=2026-11-01&to=2026-11-30')->assertOk();
        $this->actingAs($owner)->get('/reports/sales_daily/export?format=pdf')->assertStatus(302);

        $export = new ReportExport([
            'title' => 'A/B: test', 'totals' => null,
            'columns' => [['key' => 'n', 'label' => 'Name', 'type' => 'text'], ['key' => 'v', 'label' => 'Value', 'type' => 'money']],
            'rows' => [['n' => '=1+1', 'v' => '12.5000']],
        ]);
        $this->assertSame('A B  test', $export->title());
        $this->assertSame([['=1+1', 12.5]], $export->array());
    }

    public function test_invoice_pdf_is_scoped_to_the_business(): void
    {
        [, $ownerA] = $this->makeBusiness('A');
        $this->actingAs($ownerA)->get('/sales/999999/pdf')->assertNotFound();
    }

    public function test_verify_books_passes_on_clean_books_and_fails_on_a_difference(): void
    {
        [$business] = $this->makeBusiness();
        $this->artisan('pos:verify-books')->assertExitCode(0);

        $customer = app(\App\Support\TenantContext::class)->runAs($business->id, fn () => Customer::create(['code' => 'C-X', 'name' => 'Tampered', 'phone' => '1']));
        DB::table('customers')->where('id', $customer->id)->update(['balance' => '50.0000']);

        $this->artisan('pos:verify-books')->assertExitCode(1);
        $this->artisan('pos:verify-books', ['--business' => $business->id])->assertExitCode(1);
    }
}
