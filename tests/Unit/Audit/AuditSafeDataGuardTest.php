<?php

namespace Tests\Unit\Audit;

use App\Support\Audit\AuditSafeDataGuard;
use App\Support\Audit\UnsafeAuditData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class AuditSafeDataGuardTest extends TestCase
{
    public function test_it_accepts_bounded_identifiers_and_high_entropy_fingerprints(): void
    {
        (new AuditSafeDataGuard)->assertSafeMetadata([
            'source_fingerprint' => hash('sha256', 'synthetic-source'),
            'public_id' => '01J00000000000000000000000',
            'nested' => ['counts' => [0, 1, 2], 'complete' => true],
        ]);

        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('unsafeMetadata')]
    public function test_it_rejects_unsafe_metadata(array $metadata): void
    {
        $this->expectException(UnsafeAuditData::class);

        (new AuditSafeDataGuard)->assertSafeMetadata($metadata);
    }

    public function test_it_rejects_resource_values(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);

        try {
            $this->expectException(UnsafeAuditData::class);
            (new AuditSafeDataGuard)->assertSafeMetadata(['unsafe' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('unsafeEncodingAndControls')]
    public function test_it_rejects_invalid_utf8_and_c0_c1_controls(string $value): void
    {
        $this->expectException(UnsafeAuditData::class);

        (new AuditSafeDataGuard)->assertSafeText($value, 'metadata.value');
    }

    #[DataProvider('unsafeText')]
    public function test_it_rejects_secret_like_text(string $value): void
    {
        $this->expectException(UnsafeAuditData::class);

        (new AuditSafeDataGuard)->assertSafeText($value, 'reason');
    }

    public function test_it_accepts_ordinary_clinical_text_that_mentions_response_and_assertion(): void
    {
        (new AuditSafeDataGuard)->assertSafeText(
            'Clinical response: improving; the patient assertion was reviewed.',
            'reason',
        );

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsafeMetadata(): iterable
    {
        yield 'secret key' => [['nested' => ['clientSecret' => 'value']]];
        yield 'passphrase key' => [['passphrase' => 'value']];
        yield 'plural recovery codes key' => [['recoveryCodes' => ['value']]];
        yield 'recovery phrase key' => [['recovery_phrase' => 'value']];
        yield 'recovery material key' => [['recoveryMaterial' => 'value']];
        yield 'two-factor variant key' => [['twoFactorRecoveryCodes' => ['value']]];
        yield 'WebAuthn assertion key' => [['webauthnAssertion' => 'value']];
        yield 'WebAuthn response key' => [['webauthn_response' => 'value']];
        yield 'authenticator key' => [['authenticatorKey' => 'value']];
        yield 'object' => [['value' => new stdClass]];
        yield 'float' => [['value' => 1.5]];
        yield 'oversized string' => [['value' => str_repeat('x', 2049)]];
        yield 'oversized canonical payload' => [['values' => array_fill(0, 20, str_repeat('x', 1800))]];
        yield 'excessive depth' => [['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => ['j' => true]]]]]]]]]]];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeText(): iterable
    {
        yield 'private key' => ['-----BEGIN PRIVATE KEY----- synthetic'];
        yield 'bearer token' => ['Bearer abc.def.ghi'];
        yield 'basic credential' => ['Basic QWxhZGRpbjpvcGVuIHNlc2FtZQ=='];
        yield 'cookie header' => ['Cookie: session=synthetic'];
        yield 'secret assignment' => ['CLIENT_SECRET=synthetic-value'];
        yield 'client secret colon label' => ['CLIENT_SECRET: synthetic-value'];
        yield 'password colon label' => ['password: synthetic-value'];
        yield 'API key colon label' => ['api_key: synthetic-value'];
        yield 'passphrase assignment' => ['passphrase=synthetic-value'];
        yield 'recovery phrase assignment' => ['recovery_phrase: synthetic words'];
        yield 'two-factor recovery codes assignment' => ['two_factor_recovery_codes=synthetic-codes'];
        yield 'two factor assignment' => ['two-factor-code=123456'];
        yield 'WebAuthn response assignment' => ['webauthnResponse: synthetic-response'];
        yield 'WebAuthn client data assignment' => ['clientDataJSON=synthetic-client-data'];
        yield 'WebAuthn attestation object label' => ['attestationObject: synthetic-attestation'];
        yield 'WebAuthn authenticator data label' => ['authenticatorData: synthetic-authenticator-data'];
        yield 'authenticator data value' => ['{"authenticatorData":"synthetic"}'];
        yield 'quoted WebAuthn response field' => ['{"response":"synthetic-response"}'];
        yield 'unquoted WebAuthn assertion field' => ['{assertion: synthetic-assertion}'];
        yield 'secret prefix' => ['sb_secret_1234567890abcdef'];
        yield 'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature'];
        yield 'credential url' => ['https://user:password@example.test/path'];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeEncodingAndControls(): iterable
    {
        yield 'invalid UTF-8' => ["\xC3\x28"];
        yield 'NUL control' => ["safe\x00unsafe"];
        yield 'C0 line-feed control' => ["safe\nunsafe"];
        yield 'C1 control' => ["safe\xC2\x85unsafe"];
    }
}
