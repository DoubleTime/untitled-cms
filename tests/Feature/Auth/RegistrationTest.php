<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Seed the default "user" role so registration can attach it.
        Role::updateOrCreate(['slug' => 'user'], ['name' => 'user', 'permissions' => ['pages.view', 'media.view']]);
    }

    protected function tearDown(): void
    {
        $this->disableRegistration();
        parent::tearDown();
    }

    private function enableRegistration(): void
    {
        Setting::updateOrCreate(
            ['key' => 'auth.registration_enabled'],
            ['value' => true, 'type' => 'boolean', 'group' => 'auth', 'label' => 'Enable Self-Registration', 'is_public' => false]
        );
        Cache::flush();
    }

    private function disableRegistration(): void
    {
        Setting::updateOrCreate(
            ['key' => 'auth.registration_enabled'],
            ['value' => false, 'type' => 'boolean', 'group' => 'auth', 'label' => 'Enable Self-Registration', 'is_public' => false]
        );
        Cache::flush();
    }

    public function test_registration_screen_is_closed_when_disabled(): void
    {
        $this->disableRegistration();

        $response = $this->get('/register');

        $response->assertStatus(403);
    }

    public function test_registration_screen_can_be_rendered_when_enabled(): void
    {
        $this->enableRegistration();

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_and_are_redirected_to_verify_email(): void
    {
        $this->enableRegistration();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue(
            $user->roles()->where('slug', 'user')->exists(),
            'Registered user should have the user role'
        );
    }

    public function test_registration_is_blocked_when_disabled(): void
    {
        $this->disableRegistration();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_unverified_user_cannot_access_admin_routes(): void
    {
        $this->enableRegistration();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response = $this->get('/admin/pages');

        $response->assertRedirect(route('verification.notice'));
    }
}
