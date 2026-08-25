<?php

namespace App\Support\PrivilegedAccess;

use InvalidArgumentException;

final class PrivilegedAccessConfiguration
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_ENFORCE = 'enforce';

    public const ABSOLUTE_MAXIMUM_TTL_MINUTES = 30;

    /** @var list<string> */
    private const MODES = [
        self::MODE_OFF,
        self::MODE_SHADOW,
        self::MODE_ENFORCE,
    ];

    private readonly string $mode;

    private readonly int $defaultTtlMinutes;

    private readonly int $maximumTtlMinutes;

    private readonly PrivilegedAccessScopeRegistry $scopeRegistry;

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(?array $settings = null)
    {
        $settings ??= config('break_glass');

        if (! is_array($settings)) {
            throw new InvalidPrivilegedAccessConfiguration('Privileged-access configuration is missing.');
        }

        $this->mode = $this->validatedMode($settings['mode'] ?? null);
        $this->maximumTtlMinutes = $this->validatedMaximumTtl($settings['maximum_ttl_minutes'] ?? null);
        $this->defaultTtlMinutes = $this->validatedDefaultTtl(
            $settings['default_ttl_minutes'] ?? null,
            $this->maximumTtlMinutes,
        );
        $this->scopeRegistry = new PrivilegedAccessScopeRegistry($settings['scopes'] ?? null);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isOff(): bool
    {
        return $this->mode === self::MODE_OFF;
    }

    public function isShadow(): bool
    {
        return $this->mode === self::MODE_SHADOW;
    }

    public function isEnforced(): bool
    {
        return $this->mode === self::MODE_ENFORCE;
    }

    public function defaultTtlMinutes(): int
    {
        return $this->defaultTtlMinutes;
    }

    public function maximumTtlMinutes(): int
    {
        return $this->maximumTtlMinutes;
    }

    public function resolveTtlMinutes(mixed $requestedMinutes = null): int
    {
        if ($requestedMinutes === null) {
            return $this->defaultTtlMinutes;
        }

        if (! is_int($requestedMinutes) || $requestedMinutes < 1 || $requestedMinutes > $this->maximumTtlMinutes) {
            throw new InvalidArgumentException(
                "Privileged-access TTL must be an integer between 1 and {$this->maximumTtlMinutes} minutes.",
            );
        }

        return $requestedMinutes;
    }

    public function scopes(): PrivilegedAccessScopeRegistry
    {
        return $this->scopeRegistry;
    }

    private function validatedMode(mixed $mode): string
    {
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new InvalidPrivilegedAccessConfiguration(
                'Privileged-access mode must be exactly one of: off, shadow, enforce.',
            );
        }

        return $mode;
    }

    private function validatedMaximumTtl(mixed $maximumTtl): int
    {
        if (
            ! is_int($maximumTtl)
            || $maximumTtl < 1
            || $maximumTtl > self::ABSOLUTE_MAXIMUM_TTL_MINUTES
        ) {
            throw new InvalidPrivilegedAccessConfiguration(
                'Privileged-access maximum TTL must be an integer between 1 and 30 minutes.',
            );
        }

        return $maximumTtl;
    }

    private function validatedDefaultTtl(mixed $defaultTtl, int $maximumTtl): int
    {
        if (! is_int($defaultTtl) || $defaultTtl < 1 || $defaultTtl > $maximumTtl) {
            throw new InvalidPrivilegedAccessConfiguration(
                'Privileged-access default TTL must be a positive integer no greater than the configured maximum.',
            );
        }

        return $defaultTtl;
    }
}
