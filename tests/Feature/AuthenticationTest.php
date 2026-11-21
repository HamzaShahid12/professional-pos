<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_user_can_login_and_reach_dashboard(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->post('/login', ['email' => $owner->email, 'password' => 'password1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($owner);
        $this->get('/dashboard')->assertOk();
        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $owner->id]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->post('/login', ['email' => $owner->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_use_the_system(): void
    {
        [$business, $owner] = $this->makeBusiness();
        $owner->update(['is_active' => false]);

        $this->post('/login', ['email' => $owner->email, 'password' => 'password1']);
        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failed_attempts(): void
    {
        [, $owner] = $this->makeBusiness();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $owner->email, 'password' => 'bad']);
        }
        // Even the correct password is refused while locked out.
        $this->post('/login', ['email' => $owner->email, 'password' => 'password1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->actingAs($owner)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        [, $owner] = $this->makeBusiness();

        $this->post('/forgot-password', ['email' => $owner->email])->assertSessionHas('status');
        Notification::assertSentTo($owner, \Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use ($owner) {
            $this->post('/reset-password', [
                'token' => $n->token, 'email' => $owner->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
            ])->assertRedirect('/login');

            return true;
        });
        $this->assertTrue(\Hash::check('newpass123', $owner->fresh()->password));
    }

    public function test_unknown_email_gets_same_response_on_forgot_password(): void
    {
        $this->post('/forgot-password', ['email' => 'ghost@test.com'])->assertSessionHas('status');
    }

    public function test_user_can_change_password(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->actingAs($owner)->put('/profile/password', [
            'current_password' => 'password1', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(\Hash::check('brandnew99', $owner->fresh()->password));
    }

    public function test_api_login_me_and_logout(): void
    {
        [, $owner] = $this->makeBusiness();

        $token = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password1'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles']])->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', $owner->email);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_login_rejects_bad_credentials_with_json_errors(): void
    {
        [, $owner] = $this->makeBusiness();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'bad'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_api_requires_token(): void
    {
        $this->getJson('/api/v1/branches')->assertUnauthorized();
    }
}
