<?php

namespace Tests\Feature\Simulation;

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class SimulationEgressSafetyTest extends TestCase
{
    public function test_production_simulation_keeps_strong_password_rules_without_external_breach_lookup(): void
    {
        $originalEnvironment = app()->environment();
        $verifier = new class implements UncompromisedVerifier
        {
            public int $calls = 0;

            public function verify($data): bool
            {
                $this->calls++;

                return true;
            }
        };

        try {
            app()->detectEnvironment(fn (): string => 'production');
            config([
                'simulation.mode' => 'SIMULATION',
                'simulation.synthetic_only' => true,
            ]);
            app()->instance(UncompromisedVerifier::class, $verifier);

            $strong = Validator::make(
                ['password' => 'StrongPass!1234'],
                ['password' => Password::default()],
            );

            $this->assertTrue($strong->passes());

            foreach (['short', 'alllowercase!1234', 'NoNumbersHere!', 'NoSymbols1234'] as $weakPassword) {
                $weak = Validator::make(
                    ['password' => $weakPassword],
                    ['password' => Password::default()],
                );

                $this->assertTrue($weak->fails());
            }

            $this->assertSame(0, $verifier->calls);
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_non_simulation_production_preserves_breach_lookup_behavior(): void
    {
        $originalEnvironment = app()->environment();
        $verifier = new class implements UncompromisedVerifier
        {
            public int $calls = 0;

            public function verify($data): bool
            {
                $this->calls++;

                return true;
            }
        };

        try {
            app()->detectEnvironment(fn (): string => 'production');
            config([
                'simulation.mode' => 'AUTHORIZED_NON_SIMULATION',
                'simulation.synthetic_only' => false,
            ]);
            app()->instance(UncompromisedVerifier::class, $verifier);

            $validator = Validator::make(
                ['password' => 'StrongPass!1234'],
                ['password' => Password::default()],
            );

            $this->assertTrue($validator->passes());
            $this->assertSame(1, $verifier->calls);
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_simulation_forces_network_mailers_to_effective_log_transport(): void
    {
        foreach (['smtp', 'failover', 'roundrobin'] as $networkMailer) {
            config([
                'simulation.mode' => 'SIMULATION',
                'simulation.synthetic_only' => true,
                'mail.default' => $networkMailer,
                'mail.driver' => $networkMailer,
            ]);

            $this->bootApplicationServiceProvider();

            $this->assertSame('log', config('mail.default'));
            $this->assertSame('log', config('mail.driver'));
            $this->assertSame('log', app('mail.manager')->getDefaultDriver());
        }
    }

    public function test_simulation_neutralizes_legacy_mail_driver_precedence(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'mail.default' => 'log',
            'mail.driver' => 'smtp',
        ]);

        $this->bootApplicationServiceProvider();

        $this->assertSame('log', config('mail.default'));
        $this->assertSame('log', config('mail.driver'));
        $this->assertSame('log', app('mail.manager')->getDefaultDriver());
    }

    public function test_simulation_preserves_array_mail_transport_for_tests(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'mail.default' => 'array',
        ]);
        $mailConfig = config('mail');
        unset($mailConfig['driver']);
        config(['mail' => $mailConfig]);

        $this->bootApplicationServiceProvider();

        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', config('mail.driver'));
        $this->assertSame('array', app('mail.manager')->getDefaultDriver());
    }

    private function bootApplicationServiceProvider(): void
    {
        (new AppServiceProvider(app()))->boot();
    }
}
