<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Database\SchemaQualifier;
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
        $this->configurePostgresSearchPath();
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
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
                $event->connection->statement('SET search_path TO '.$quoted);
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

    /**
     * Register capability gates from Capability constants.
     * System administrators are break-glass via User::canCapability().
     */
    protected function configureAuthorization(): void
    {
        foreach (Capability::all() as $capability) {
            Gate::define($capability, function (?User $user) use ($capability): bool {
                return $user instanceof User && $user->canCapability($capability);
            });
        }
    }
}
