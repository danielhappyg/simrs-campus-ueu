<?php

namespace Tests\Feature;

use App\Support\Http\DeploymentHostBoundary;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

class TrustedEdgeForwardingBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        TrustHosts::flushState();
        Request::setTrustedProxies([], 0);
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_application_bootstrap_binds_the_reviewed_edge_policy(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString(
            'at: static fn (): array => DeploymentHostBoundary::patterns()',
            $bootstrap,
        );
        $this->assertStringContainsString(
            'Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO',
            $bootstrap,
        );
        $this->assertStringContainsString(
            "getenv('RENDER') === 'true'",
            $bootstrap,
        );
    }

    public function test_direct_application_entrypoint_ignores_forwarding_headers(): void
    {
        Route::get('/_test/trusted-edge-boundary', static fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'base_url' => $request->getBaseUrl(),
        ]));

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_HOST' => 'canonical.example',
            'SERVER_PORT' => '80',
        ])->withHeaders([
            'Host' => 'canonical.example',
            'Forwarded' => 'for=192.0.2.44;host=forwarded.invalid;proto=https',
            'X-Forwarded-For' => '203.0.113.66',
            'X-Forwarded-Host' => 'attacker.invalid',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Prefix' => '/attacker-prefix',
        ])->getJson('/_test/trusted-edge-boundary')->assertExactJson([
            'ip' => '10.0.0.9',
            'host' => 'localhost',
            'scheme' => 'http',
            'base_url' => '',
        ]);
    }

    public function test_vercel_edge_subset_accepts_only_platform_ip_and_protocol(): void
    {
        TrustProxies::at('REMOTE_ADDR');
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        $request = Request::create(
            uri: 'http://canonical.example/login',
            server: [
                'REMOTE_ADDR' => '10.0.0.9',
                'SERVER_PORT' => '80',
                'HTTP_FORWARDED' => 'for=192.0.2.44;host=forwarded.invalid;proto=http',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
                'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT' => '8443',
                'HTTP_X_FORWARDED_PREFIX' => '/attacker-prefix',
            ],
        );

        $observed = (new TrustProxies)->handle($request, static fn (Request $trusted): array => [
            'ip' => $trusted->ip(),
            'host' => $trusted->getHost(),
            'scheme' => $trusted->getScheme(),
            'port' => $trusted->getPort(),
            'base_url' => $trusted->getBaseUrl(),
            'url' => $trusted->url(),
        ]);

        $this->assertSame([
            'ip' => '198.51.100.20',
            'host' => 'canonical.example',
            'scheme' => 'https',
            'port' => 443,
            'base_url' => '',
            'url' => 'https://canonical.example/login',
        ], $observed);
    }

    public function test_render_edge_subset_accepts_protocol_but_not_forwarded_identity(): void
    {
        TrustProxies::at('REMOTE_ADDR');
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_PROTO);

        $request = Request::create(
            uri: 'http://canonical.example/login',
            server: [
                'REMOTE_ADDR' => '10.0.0.9',
                'SERVER_PORT' => '80',
                'HTTP_FORWARDED' => 'for=192.0.2.44;host=forwarded.invalid;proto=http',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
                'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PREFIX' => '/attacker-prefix',
            ],
        );

        $observed = (new TrustProxies)->handle($request, static fn (Request $trusted): array => [
            'ip' => $trusted->ip(),
            'host' => $trusted->getHost(),
            'scheme' => $trusted->getScheme(),
            'base_url' => $trusted->getBaseUrl(),
            'url' => $trusted->url(),
        ]);

        $this->assertSame([
            'ip' => '10.0.0.9',
            'host' => 'canonical.example',
            'scheme' => 'https',
            'base_url' => '',
            'url' => 'https://canonical.example/login',
        ], $observed);
    }

    public function test_host_allowlist_rejects_unassigned_host(): void
    {
        config()->set('app.url', 'https://canonical.example');
        TrustHosts::at(
            static fn (): array => DeploymentHostBoundary::patterns(),
            subdomains: false,
        );

        $middleware = new TrustHosts(app());
        $this->assertSame(['^canonical\.example$'], $middleware->hosts());
        Request::setTrustedHosts($middleware->hosts());

        $this->assertSame(
            'canonical.example',
            Request::create('https://canonical.example/login')->getHost(),
        );

        $this->expectException(SuspiciousOperationException::class);

        Request::create('https://attacker.invalid/login')->getHost();
    }
}
