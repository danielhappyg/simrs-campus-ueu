<?php

namespace Tests\Unit\Support\PrivilegedAccess;

use App\Support\Authorization\Capability;
use App\Support\PrivilegedAccess\InvalidPrivilegedAccessConfiguration;
use App\Support\PrivilegedAccess\PrivilegedAccessScopeRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class PrivilegedAccessScopeRegistryTest extends TestCase
{
    public function test_registry_returns_only_the_three_exact_canonical_bundles(): void
    {
        $registry = new PrivilegedAccessScopeRegistry(config('break_glass.scopes'));

        $this->assertSame([
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
        ], $registry->all());

        $this->assertSame(
            [Capability::USER_MANAGE, Capability::AUDIT_VIEW],
            $registry->capabilitiesFor('security-containment'),
        );
    }

    public function test_unknown_empty_and_wildcard_scope_requests_are_rejected(): void
    {
        $registry = new PrivilegedAccessScopeRegistry(config('break_glass.scopes'));

        foreach (['unknown', '', '*', 'identity-*'] as $scope) {
            try {
                $registry->capabilitiesFor($scope);
                $this->fail('Unknown or wildcard scope should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Unknown privileged-access scope', $exception->getMessage());
            }
        }
    }

    public function test_unknown_or_missing_scope_names_fail_configuration_validation(): void
    {
        $unknown = $this->validScopes();
        $unknown['future-recovery'] = [Capability::AUDIT_VIEW];
        $this->expectConfigurationFailure($unknown, 'scope names');

        $missing = $this->validScopes();
        unset($missing['security-containment']);
        $this->expectConfigurationFailure($missing, 'scope names');
    }

    public function test_wildcard_duplicate_unknown_and_non_capability_entries_fail_closed(): void
    {
        $malformedEntries = [
            ['value' => '*', 'message' => 'wildcard'],
            ['value' => 'user.*', 'message' => 'wildcard'],
            ['value' => 'future.capability', 'message' => 'unknown capability'],
            ['value' => 123, 'message' => 'invalid'],
            ['value' => null, 'message' => 'invalid'],
        ];

        foreach ($malformedEntries as $entry) {
            $scopes = $this->validScopes();
            $scopes['security-containment'][0] = $entry['value'];
            $this->expectConfigurationFailure($scopes, $entry['message']);
        }

        $duplicate = $this->validScopes();
        $duplicate['security-containment'][] = Capability::USER_MANAGE;
        $this->expectConfigurationFailure($duplicate, 'duplicate capability');
    }

    public function test_known_but_noncanonical_capability_bundle_fails_closed(): void
    {
        $scopes = $this->validScopes();
        $scopes['security-containment'][] = Capability::ROLE_MANAGE;

        $this->expectConfigurationFailure($scopes, 'exactly match');
    }

    public function test_scope_capabilities_must_be_an_array(): void
    {
        $scopes = $this->validScopes();
        $scopes['identity-recovery'] = Capability::ROLE_MANAGE;

        $this->expectConfigurationFailure($scopes, 'capability array');
    }

    public function test_capability_order_in_configuration_cannot_change_the_canonical_snapshot_order(): void
    {
        $scopes = $this->validScopes();
        $scopes['platform-recovery'] = array_reverse($scopes['platform-recovery']);

        $registry = new PrivilegedAccessScopeRegistry($scopes);

        $this->assertSame([
            Capability::USER_MANAGE,
            Capability::ROLE_MANAGE,
            Capability::AUDIT_VIEW,
            Capability::MASTER_MANAGE,
            Capability::SYNTHETIC_RESET,
        ], $registry->capabilitiesFor('platform-recovery'));
    }

    /** @return array<string, mixed> */
    private function validScopes(): array
    {
        /** @var array<string, mixed> $scopes */
        $scopes = config('break_glass.scopes');

        return $scopes;
    }

    /** @param array<string, mixed> $scopes */
    private function expectConfigurationFailure(array $scopes, string $message): void
    {
        try {
            new PrivilegedAccessScopeRegistry($scopes);
            $this->fail('Invalid privileged-access scope configuration should fail closed.');
        } catch (InvalidPrivilegedAccessConfiguration $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
