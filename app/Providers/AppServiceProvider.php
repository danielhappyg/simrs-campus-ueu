<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAccommodationTariffSqlWriteGuard;
use App\Support\Finance\FinanceLaboratoryTariffSqlWriteGuard;
use App\Support\Finance\FinanceRadiologyTariffSqlWriteGuard;
use App\Support\Finance\FinanceSqlWriteGuard;
use App\Support\Finance\FinanceTariffSqlWriteGuard;
use App\Support\Inpatient\InpatientDocumentationSqlWriteGuard;
use App\Support\Inpatient\InpatientLocationSqlWriteGuard;
use App\Support\Inpatient\InpatientMasterSqlWriteGuard;
use App\Support\Laboratory\LaboratorySqlWriteGuard;
use App\Support\Pharmacy\PharmacySqlWriteGuard;
use App\Support\PrivilegedAccess\PrivilegedAccessShadowResolver;
use App\Support\Radiology\RadiologySqlWriteGuard;
use App\Support\Warehouse\WarehouseSqlWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSimulationEgressGuards();
        $this->configurePostgresSearchPath();
        $this->configureInpatientMasterSqlWriteGuard();
        $this->configureInpatientDocumentationSqlWriteGuard();
        $this->configureInpatientLocationSqlWriteGuard();
        $this->configureRadiologySqlWriteGuard();
        $this->configureLaboratorySqlWriteGuard();
        $this->configurePharmacySqlWriteGuard();
        $this->configureFinanceSqlWriteGuard();
        $this->configureFinanceTariffSqlWriteGuard();
        $this->configureFinanceRadiologyTariffSqlWriteGuard();
        $this->configureFinanceLaboratoryTariffSqlWriteGuard();
        $this->configureFinanceAccommodationTariffSqlWriteGuard();
        $this->configureWarehouseSqlWriteGuard();
        $this->configureAuthorization();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(function (): ?Password {
            if (! app()->isProduction()) {
                return null;
            }

            $rule = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            return config('simulation.mode') === 'SIMULATION'
                ? $rule
                : $rule->uncompromised();
        });
    }

    /**
     * Keep synthetic simulation notifications inside the application boundary.
     */
    protected function configureSimulationEgressGuards(): void
    {
        if (config('simulation.mode') !== 'SIMULATION') {
            return;
        }

        $mailer = config('mail.driver', config('mail.default'));
        $safeMailer = in_array($mailer, ['array', 'log'], true) ? $mailer : 'log';

        config([
            'mail.default' => $safeMailer,
            'mail.driver' => $safeMailer,
        ]);
    }

    /**
     * Re-apply PostgreSQL search_path on every established connection.
     * Boot-time SET alone is unreliable under serverless + poolers.
     */
    protected function configurePostgresSearchPath(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'pgsql') {
                return;
            }

            $schemas = SchemaQualifier::searchPathSchemas();

            if ($schemas === []) {
                return;
            }

            $quoted = collect($schemas)
                ->map(fn (string $name): string => '"'.str_replace('"', '""', $name).'"')
                ->implode(', ');

            try {
                // SET LOCAL is only valid inside a transaction. Outside one
                // (typical serverless + PgBouncer autocommit) it errors on
                // every request; session SET is the fallback.
                if ($event->connection->transactionLevel() > 0) {
                    $event->connection->statement('SET LOCAL search_path TO '.$quoted);
                } else {
                    $event->connection->statement('SET search_path TO '.$quoted);
                }
            } catch (\Throwable) {
                // Connection may be read-only or mid-transaction in edge cases.
            }
        });

        if (config('database.default') === 'pgsql') {
            $schemas = SchemaQualifier::searchPathSchemas();

            if ($schemas !== []) {
                $quoted = collect($schemas)
                    ->map(fn (string $name): string => '"'.str_replace('"', '""', $name).'"')
                    ->implode(', ');

                try {
                    DB::statement('SET search_path TO '.$quoted);
                } catch (\Throwable) {
                    // Connection may be unavailable during early boot / package discovery.
                }
            }
        }
    }

    private function configureInpatientMasterSqlWriteGuard(): void
    {
        $guard = app(InpatientMasterSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };

        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureInpatientDocumentationSqlWriteGuard(): void
    {
        $guard = app(InpatientDocumentationSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };

        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureInpatientLocationSqlWriteGuard(): void
    {
        $guard = app(InpatientLocationSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };

        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureRadiologySqlWriteGuard(): void
    {
        $guard = app(RadiologySqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureLaboratorySqlWriteGuard(): void
    {
        if (! class_exists(LaboratorySqlWriteGuard::class)) {
            return;
        }

        $guard = app(LaboratorySqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configurePharmacySqlWriteGuard(): void
    {
        $guard = app(PharmacySqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureFinanceSqlWriteGuard(): void
    {
        $guard = app(FinanceSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureFinanceTariffSqlWriteGuard(): void
    {
        $guard = app(FinanceTariffSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureFinanceRadiologyTariffSqlWriteGuard(): void
    {
        $guard = app(FinanceRadiologyTariffSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureFinanceLaboratoryTariffSqlWriteGuard(): void
    {
        $guard = app(FinanceLaboratoryTariffSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureFinanceAccommodationTariffSqlWriteGuard(): void
    {
        $guard = app(FinanceAccommodationTariffSqlWriteGuard::class);
        $register = static function ($connection) use ($guard): void {
            $connection->beforeExecuting(static function (string $query) use ($guard): void {
                $guard->assertAllowed($query);
            });
        };
        $register(DB::connection());
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    private function configureWarehouseSqlWriteGuard(): void
    {
        $guard = app(WarehouseSqlWriteGuard::class);
        $registered = new \SplObjectStorage;
        $register = static function ($connection) use ($guard, $registered): void {
            if ($registered->contains($connection)) {
                return;
            }
            $registered->attach($connection);
            $connection->beforeExecuting(static function (string $query, array $bindings) use ($connection, $guard): void {
                $guard->assertAllowed($query, $connection, $bindings);
            });
        };
        $register(DB::connection());
        foreach (DB::getFacadeRoot()->getConnections() as $connection) {
            $register($connection);
        }
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            $register($event->connection);
        });
    }

    /**
     * Register capability gates from Capability constants.
     *
     * BG-03 observes a proposed scoped decision after the current
     * User::canCapability() result has been computed. The resolver returns the
     * exact legacy result in off, shadow, and accidental enforce modes.
     */
    protected function configureAuthorization(): void
    {
        foreach (Capability::all() as $capability) {
            Gate::define($capability, function (?User $user) use ($capability): bool {
                if (! $user instanceof User) {
                    return false;
                }

                if ($user->is_system_administrator && str_starts_with($capability, 'warehouse.')) {
                    return false;
                }

                $legacyAllowed = $user->canCapability($capability);

                try {
                    /** @var PrivilegedAccessShadowResolver $resolver */
                    $resolver = app(PrivilegedAccessShadowResolver::class);
                    $request = app()->bound('request') ? request() : null;

                    return $resolver->resolve($user, $capability, $legacyAllowed, $request);
                } catch (\Throwable) {
                    return $legacyAllowed;
                }
            });
        }
    }
}
