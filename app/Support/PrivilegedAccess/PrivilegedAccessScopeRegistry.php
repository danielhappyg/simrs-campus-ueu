<?php

namespace App\Support\PrivilegedAccess;

use App\Support\Authorization\Capability;
use InvalidArgumentException;

final class PrivilegedAccessScopeRegistry
{
    /** @var array<string, list<string>> */
    private const CANONICAL_SCOPES = [
        'security-containment' => [
            Capability::USER_MANAGE,
            Capability::AUDIT_VIEW,
        ],
        'identity-recovery' => [
            Capability::USER_MANAGE,
            Capability::ROLE_MANAGE,
            Capability::AUDIT_VIEW,
        ],
        'platform-recovery' => [
            Capability::USER_MANAGE,
            Capability::ROLE_MANAGE,
            Capability::AUDIT_VIEW,
            Capability::MASTER_MANAGE,
            Capability::SYNTHETIC_RESET,
        ],
    ];

    /** @var array<string, list<string>> */
    private array $scopes;

    public function __construct(mixed $configuredScopes)
    {
        $this->validate($configuredScopes);
        $this->scopes = self::CANONICAL_SCOPES;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->scopes;
    }

    /** @return list<string> */
    public function capabilitiesFor(string $scope): array
    {
        if ($scope === '' || str_contains($scope, '*') || ! array_key_exists($scope, $this->scopes)) {
            throw new InvalidArgumentException("Unknown privileged-access scope [{$scope}].");
        }

        return $this->scopes[$scope];
    }

    private function validate(mixed $configuredScopes): void
    {
        if (! is_array($configuredScopes)) {
            throw new InvalidPrivilegedAccessConfiguration('Privileged-access scopes must be an array.');
        }

        $configuredNames = array_keys($configuredScopes);
        $canonicalNames = array_keys(self::CANONICAL_SCOPES);
        sort($configuredNames);
        sort($canonicalNames);

        if ($configuredNames !== $canonicalNames) {
            throw new InvalidPrivilegedAccessConfiguration(
                'Privileged-access scope names must exactly match the canonical allowlist.',
            );
        }

        $knownCapabilities = Capability::all();

        foreach (self::CANONICAL_SCOPES as $scope => $canonicalCapabilities) {
            $configuredCapabilities = $configuredScopes[$scope] ?? null;

            if (! is_array($configuredCapabilities)) {
                throw new InvalidPrivilegedAccessConfiguration(
                    "Privileged-access scope [{$scope}] must contain a capability array.",
                );
            }

            $validatedCapabilities = [];

            foreach ($configuredCapabilities as $capability) {
                if (! is_string($capability) || $capability === '' || str_contains($capability, '*')) {
                    throw new InvalidPrivilegedAccessConfiguration(
                        "Privileged-access scope [{$scope}] contains an invalid or wildcard capability.",
                    );
                }

                if (! in_array($capability, $knownCapabilities, true)) {
                    throw new InvalidPrivilegedAccessConfiguration(
                        "Privileged-access scope [{$scope}] contains an unknown capability [{$capability}].",
                    );
                }

                if (in_array($capability, $validatedCapabilities, true)) {
                    throw new InvalidPrivilegedAccessConfiguration(
                        "Privileged-access scope [{$scope}] contains duplicate capability [{$capability}].",
                    );
                }

                $validatedCapabilities[] = $capability;
            }

            $configuredSet = $validatedCapabilities;
            $canonicalSet = $canonicalCapabilities;
            sort($configuredSet);
            sort($canonicalSet);

            if ($configuredSet !== $canonicalSet) {
                throw new InvalidPrivilegedAccessConfiguration(
                    "Privileged-access scope [{$scope}] must exactly match its canonical capability bundle.",
                );
            }
        }
    }
}
