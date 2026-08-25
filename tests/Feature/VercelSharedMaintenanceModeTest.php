<?php

namespace Tests\Feature;

use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class VercelSharedMaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->configureMaintenanceMode('file', 'database');

        parent::tearDown();
    }

    public function test_vercel_entrypoint_defaults_to_shared_database_maintenance_mode(): void
    {
        $entrypoint = file_get_contents(base_path('api/index.php'));

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString(
            <<<'PHP'
$setDefaultEnvironment('APP_MAINTENANCE_DRIVER', 'cache');
PHP,
            $entrypoint,
        );
        $this->assertStringContainsString(
            <<<'PHP'
$setDefaultEnvironment('APP_MAINTENANCE_STORE', 'database');
PHP,
            $entrypoint,
        );
    }

    public function test_shared_maintenance_marker_drains_application_traffic_but_keeps_health_available(): void
    {
        $this->configureMaintenanceMode('cache', 'database');
        $auditRowsBefore = DB::table('audit_events')->count();
        $sessionRowsBefore = DB::table('sessions')->count();

        try {
            $this->assertSame(0, Artisan::call('down', ['--retry' => 60]));

            $this->get('/login')
                ->assertStatus(503)
                ->assertHeader('Retry-After', '60');
            $this->post('/pendaftaran/rawat-jalan')->assertStatus(503);
            $this->get('/up')->assertOk();

            $this->assertSame($auditRowsBefore, DB::table('audit_events')->count());
            $this->assertSame($sessionRowsBefore, DB::table('sessions')->count());
        } finally {
            Artisan::call('up');
        }

        $this->get('/login')->assertOk();
    }

    public function test_invalid_maintenance_driver_cannot_silently_leave_traffic_open(): void
    {
        $this->configureMaintenanceMode('invalid-open-mode', 'database');

        $this->expectException(InvalidArgumentException::class);

        app()->maintenanceMode()->active();
    }

    private function configureMaintenanceMode(string $driver, string $store): void
    {
        config()->set([
            'app.maintenance.driver' => $driver,
            'app.maintenance.store' => $store,
        ]);

        app(MaintenanceModeManager::class)->forgetDrivers();
        app()->forgetInstance(MaintenanceModeContract::class);
    }
}
