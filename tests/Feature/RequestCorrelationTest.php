<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Http\RequestCorrelation;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/request-correlation/failure', function (): never {
            throw new RuntimeException('synthetic failure detail must not leave the server');
        })->name('test.request-correlation.failure');

        Route::middleware('web')->get('/_test/request-correlation/forbidden', function (): never {
            abort(403, 'synthetic forbidden detail');
        })->name('test.request-correlation.forbidden');
    }

    public function test_web_response_contains_a_ulid_request_identifier(): void
    {
        $response = $this->get(route('login'));
        $requestId = $response->headers->get('X-Request-Id');

        $response->assertOk();
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestId);
    }

    public function test_unhandled_failure_response_and_structured_log_share_the_same_safe_request_identifier(): void
    {
        config(['app.debug' => false]);
        Log::spy();

        $response = $this->get('/_test/request-correlation/failure');
        $requestId = $response->headers->get(RequestCorrelation::HEADER);

        $response->assertStatus(500);
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestId);
        $this->assertStringNotContainsString('synthetic failure detail', $response->getContent());

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($requestId): bool {
                return $message === 'Unhandled HTTP exception.'
                    && array_keys($context) === [
                        'request_id',
                        'exception_class',
                        'exception_fingerprint',
                        'http_method',
                        'route_name',
                        'route_template',
                    ]
                    && $context['request_id'] === $requestId
                    && $context['exception_class'] === RuntimeException::class
                    && preg_match('/\A[0-9a-f]{64}\z/', $context['exception_fingerprint']) === 1
                    && $context['http_method'] === 'GET'
                    && $context['route_name'] === 'test.request-correlation.failure'
                    && $context['route_template'] === '_test/request-correlation/failure';
            });
    }

    public function test_exception_rendered_forbidden_response_and_audit_row_keep_the_same_request_identifier(): void
    {
        $user = User::factory()->create();
        Log::spy();

        $response = $this->actingAs($user)->get('/_test/request-correlation/forbidden');
        $requestId = $response->headers->get(RequestCorrelation::HEADER);

        $response->assertForbidden();
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestId);
        $this->assertSame(
            $requestId,
            DB::table('audit_events')
                ->where('action', 'authorization.denied')
                ->where('resource_id', 'test.request-correlation.forbidden')
                ->value('request_correlation_id'),
        );

        Log::shouldHaveReceived('notice')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Forbidden HTTP exception.'
                && array_keys($context) === [
                    'request_id',
                    'exception_class',
                    'http_method',
                    'route_name',
                    'route_template',
                ]
                && $context['request_id'] === $requestId
                && $context['http_method'] === 'GET'
                && $context['route_name'] === 'test.request-correlation.forbidden'
                && $context['route_template'] === '_test/request-correlation/forbidden');
    }

    public function test_console_exception_keeps_laravels_default_diagnostic_reporter(): void
    {
        $request = Request::create('https://simrs.example.invalid', 'GET');
        $this->app->instance('request', $request);
        $exception = new RuntimeException('console diagnostic must remain available');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(
                'console diagnostic must remain available',
                Mockery::on(fn (array $context): bool => ($context['exception'] ?? null) === $exception),
            );
        $this->app->instance(LoggerInterface::class, $logger);
        Log::spy();

        $this->app->make(ExceptionHandler::class)->report($exception);

        Log::shouldNotHaveReceived('error', ['Unhandled HTTP exception.', Mockery::any()]);
    }
}
