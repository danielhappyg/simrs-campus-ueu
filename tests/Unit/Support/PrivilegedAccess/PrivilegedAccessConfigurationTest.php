<?php

namespace Tests\Unit\Support\PrivilegedAccess;

use App\Support\PrivilegedAccess\InvalidPrivilegedAccessConfiguration;
use App\Support\PrivilegedAccess\PrivilegedAccessConfiguration;
use InvalidArgumentException;
use Tests\TestCase;

class PrivilegedAccessConfigurationTest extends TestCase
{
    public function test_default_configuration_is_off_with_fifteen_minute_default_and_thirty_minute_hard_maximum(): void
    {
        $configuration = new PrivilegedAccessConfiguration;

        $this->assertSame(PrivilegedAccessConfiguration::MODE_OFF, $configuration->mode());
        $this->assertTrue($configuration->isOff());
        $this->assertFalse($configuration->isShadow());
        $this->assertFalse($configuration->isEnforced());
        $this->assertSame(15, $configuration->defaultTtlMinutes());
        $this->assertSame(30, $configuration->maximumTtlMinutes());
        $this->assertSame(15, $configuration->resolveTtlMinutes());
        $this->assertSame(30, $configuration->resolveTtlMinutes(30));
    }

    public function test_only_exact_off_shadow_and_enforce_modes_are_accepted(): void
    {
        foreach (['off', 'shadow', 'enforce'] as $mode) {
            $settings = $this->validSettings();
            $settings['mode'] = $mode;

            $this->assertSame($mode, (new PrivilegedAccessConfiguration($settings))->mode());
        }
    }

    public function test_invalid_modes_fail_closed(): void
    {
        foreach (['', 'OFF', 'observe', '*', true, null] as $mode) {
            $settings = $this->validSettings();
            $settings['mode'] = $mode;

            try {
                new PrivilegedAccessConfiguration($settings);
                $this->fail('Invalid privileged-access mode should fail closed.');
            } catch (InvalidPrivilegedAccessConfiguration $exception) {
                $this->assertStringContainsString('mode', $exception->getMessage());
            }
        }
    }

    public function test_invalid_configured_ttls_fail_closed(): void
    {
        $invalidPairs = [
            ['default' => 0, 'maximum' => 30],
            ['default' => 31, 'maximum' => 30],
            ['default' => 16, 'maximum' => 15],
            ['default' => '15', 'maximum' => 30],
            ['default' => 15, 'maximum' => '30'],
            ['default' => false, 'maximum' => 30],
            ['default' => 15, 'maximum' => false],
            ['default' => 15, 'maximum' => 31],
            ['default' => 15, 'maximum' => 0],
        ];

        foreach ($invalidPairs as $pair) {
            $settings = $this->validSettings();
            $settings['default_ttl_minutes'] = $pair['default'];
            $settings['maximum_ttl_minutes'] = $pair['maximum'];

            $this->expectConfigurationFailure($settings, 'TTL');
        }
    }

    public function test_requested_ttl_must_be_an_integer_inside_the_configured_ceiling(): void
    {
        $settings = $this->validSettings();
        $settings['default_ttl_minutes'] = 10;
        $settings['maximum_ttl_minutes'] = 20;
        $configuration = new PrivilegedAccessConfiguration($settings);

        $this->assertSame(10, $configuration->resolveTtlMinutes());
        $this->assertSame(1, $configuration->resolveTtlMinutes(1));
        $this->assertSame(20, $configuration->resolveTtlMinutes(20));

        foreach ([0, 21, -1, '15', 15.0, '*'] as $invalidTtl) {
            try {
                $configuration->resolveTtlMinutes($invalidTtl);
                $this->fail('Invalid requested TTL should fail closed.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('TTL', $exception->getMessage());
            }
        }
    }

    public function test_the_entire_scope_configuration_is_validated_even_when_mode_is_off(): void
    {
        $settings = $this->validSettings();
        $settings['mode'] = 'off';
        $settings['scopes']['security-containment'][] = '*';

        $this->expectConfigurationFailure($settings, 'wildcard');
    }

    /** @return array<string, mixed> */
    private function validSettings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = config('break_glass');

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function expectConfigurationFailure(array $settings, string $message): void
    {
        try {
            new PrivilegedAccessConfiguration($settings);
            $this->fail('Invalid privileged-access configuration should fail closed.');
        } catch (InvalidPrivilegedAccessConfiguration $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
