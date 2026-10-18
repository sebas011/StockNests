<?php

namespace Tests\Feature\StockNest;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Tests\TestCase;

class DeploymentSecurityTest extends TestCase
{
    public function test_http_local_setup_has_nonce_headers_without_hsts(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeaderMissing('Strict-Transport-Security');

        $nonce = Vite::cspNonce();
        $this->assertNotEmpty($nonce);
        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $response->headers->get('Content-Security-Policy'));
        $response->assertSee('nonce="'.$nonce.'"', false);
        $this->get('/login');
        $this->assertNotSame($nonce, Vite::cspNonce());
    }

    public function test_direct_https_adds_hsts(): void
    {
        $this->get('https://stocknest.example/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_an_untrusted_forwarded_https_header_does_not_add_hsts(): void
    {
        config(['security.trusted_proxies' => []]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.17'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'forged.example'])
            ->get('/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->assertSame('localhost', request()->getHost());
    }

    public function test_only_an_explicit_reverse_proxy_can_supply_forwarded_https(): void
    {
        config(['security.trusted_proxies' => ['192.0.2.20']]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'forged.example'])
            ->get('/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->assertSame('localhost', request()->getHost());
    }

    public function test_the_default_policy_rejects_weak_passwords_outside_production(): void
    {
        foreach (['password', 'abcdefghijklmnop', 'ALL-UPPERCASE42!', 'NoSymbolsOmitted42'] as $password) {
            $this->assertTrue(Validator::make(['password' => $password], ['password' => Password::default()])->fails());
        }

        $this->assertTrue(Validator::make(['password' => 'Local-strong-password42!'], ['password' => Password::default()])->passes());
    }

    public function test_production_refuses_debug_mode(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Debug mode must be disabled in production.');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_offline_portable_production_passwords_do_not_require_an_external_service(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'stocknest.portable' => true]);
        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(Validator::make(['password' => 'Offline-strong-password42!'], ['password' => Password::default()])->passes());
    }
}
