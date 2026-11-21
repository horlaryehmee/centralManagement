<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_page_visit_is_sent_to_https_when_site_uses_https(): void
    {
        config(['app.url' => 'https://central.example.com']);

        $this->get('http://central.example.com/login?x=1')
            ->assertRedirect('https://central.example.com/login?x=1')
            ->assertStatus(301);
    }

    public function test_https_behind_a_proxy_is_not_redirected(): void
    {
        config(['app.url' => 'https://central.example.com']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://central.example.com/login')
            ->assertOk();
    }

    public function test_no_redirect_when_site_address_is_http(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('http://localhost/login')->assertOk();
    }

    public function test_expired_form_goes_back_with_message_instead_of_419(): void
    {
        Route::middleware('web')->post('/_test-expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->from('/login')->post('/_test-expired', ['email' => 'a@b.c', 'password' => 'secret'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', 'Your session expired. Please try again.')
            ->assertSessionMissing('_old_input.password');
    }
}
