<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public registration is intentionally disabled — this is an internal ERP.
 * Admin creates users via the Users management page. The /register route is
 * not exposed (see routes/auth.php).
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_exposed(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_endpoint_is_not_exposed(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
    }
}
