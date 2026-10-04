<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = $this->login();

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertGuest();
    }

    public function test_unauthenticated_logout_returns_unauthorized(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_inactive_authenticated_user_can_still_logout(): void
    {
        $user = $this->login();
        $user->update(['is_active' => false]);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertGuest();
    }

    public function test_me_returns_unauthorized_after_logout(): void
    {
        $this->login();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    private function login(): User
    {
        $user = User::factory()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk();

        return $user;
    }
}
