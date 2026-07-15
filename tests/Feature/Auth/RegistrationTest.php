<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_public_registration_is_disabled(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));

        $this->get('/register')->assertNotFound();
    }

    public function test_registration_submission_is_not_routable(): void
    {
        $this->post('/register', [
            'name' => 'Unprovisioned User',
            'email' => 'unprovisioned@example.invalid',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
    }
}
