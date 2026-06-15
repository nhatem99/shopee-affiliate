<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Login ────────────────────────────────────────────────────────────────

    public function test_login_page_is_accessible_to_guests(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->get('/login')->assertRedirect('/');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = $this->createUser(['password' => 'password123']);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->createUser();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
    }

    // ── Register ─────────────────────────────────────────────────────────────

    public function test_register_page_is_accessible_to_guests(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $this->setUpRoles();

        $this->post('/register', [
            'name'                  => 'Nguyen Van A',
            'email'                 => 'test@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        $existing = $this->createUser(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name'                  => 'Other User',
            'email'                 => 'taken@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('email');
    }

    public function test_register_fails_when_password_not_confirmed(): void
    {
        $this->setUpRoles();

        $this->post('/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');
    }

    public function test_register_fails_when_password_too_short(): void
    {
        $this->setUpRoles();

        $this->post('/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    // ── Logout ───────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_logout(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_guest_cannot_access_logout(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }
}
