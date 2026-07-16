<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use JsonException;
use Throwable;

class HostingPreflightCommand extends Command
{
    /** @var array<string, string> */
    private const MANUAL_CHECKS = [
        'account.plan_version' => 'Record the exact Hostinger product, plan version, and purchase date.',
        'ssh.access_restrictions' => 'Verify SSH is enabled and record its home-directory and command restrictions.',
        'build.composer_strategy' => 'Verify how production Composer dependencies will be built without exposing credentials.',
        'build.node_strategy' => 'Confirm frontend assets are built once in CI and Node is not required on the web host.',
        'cron.scheduler' => 'Verify a one-minute Laravel scheduler cron and retain a successful execution record.',
        'queue.execution' => 'Prove the selected database-queue execution and restart strategy under plan limits.',
        'storage.private_isolation' => 'Verify private storage is outside the public document root and survives release switches.',
        'backup.retention_restore' => 'Record backup frequency/retention and complete an isolated file plus database restore.',
        'release.atomic_switch' => 'Prove release-directory creation and an atomic or equivalent reversible switch.',
        'tls.dns_staging' => 'Verify a separate staging hostname, valid TLS, and no production credential reuse.',
        'logs.retention' => 'Record application/web log locations, access restrictions, retention, and redaction limits.',
        'resources.limits' => 'Record CPU, memory, process, disk, inode, database, and cron limits for the exact plan version.',
        'github.environment_gate' => 'Record the private-repository environment/secrets approval mechanism available on the GitHub plan.',
        'ssh.host_key_pin' => 'Record and independently verify the Hostinger SSH host-key fingerprint before automation.',
    ];

    /** @var list<string> */
    private const REQUIRED_PHP_EXTENSIONS = [
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'hash',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'session',
        'tokenizer',
        'xml',
    ];

    private const CONCLUSIVE_EVIDENCE_MAX_AGE_DAYS = 30;

    protected $signature = 'ops:hosting-preflight
        {--json : Emit the complete machine-readable preflight report}
        {--evidence= : Read a sanitized Hostinger staging evidence JSON file}';

    protected $description = 'Run a read-only hosting capability and safety preflight';

    public function handle(): int
    {
        $url = (string) config('app.url');
        $missingExtensions = array_values(array_filter(
            self::REQUIRED_PHP_EXTENSIONS,
            fn (string $extension): bool => ! extension_loaded($extension),
        ));
        $privateRoot = (string) config('filesystems.disks.local.root');
        $resolvedPrivateRoot = realpath($privateRoot) ?: $privateRoot;
        $resolvedPublicRoot = realpath(public_path()) ?: public_path();
        $privateRootIsOutsidePublic = $resolvedPrivateRoot !== ''
            && ! str_starts_with(
                rtrim($resolvedPrivateRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                rtrim($resolvedPublicRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
            );
        $writablePaths = [$privateRoot, storage_path(), base_path('bootstrap/cache')];
        $unwritablePaths = array_values(array_filter(
            $writablePaths,
            fn (string $path): bool => ! is_dir($path) || ! is_writable($path),
        ));
        $databaseError = null;

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            $databaseError = $exception::class;
        }

        $healthRouteExists = collect(Route::getRoutes()->getRoutes())->contains(
            fn ($route): bool => $route->uri() === 'up' && in_array('GET', $route->methods(), true),
        );
        $checks = [
            $this->check(
                'runtime.php_version',
                version_compare(PHP_VERSION, '8.3.0', '>='),
                version_compare(PHP_VERSION, '8.3.0', '>=')
                    ? 'PHP '.PHP_VERSION.' satisfies Laravel 13 minimum 8.3.'
                    : 'PHP 8.3 or newer is required; observed '.PHP_VERSION.'.',
            ),
            $this->check(
                'runtime.php_extensions',
                $missingExtensions === [],
                $missingExtensions === []
                    ? 'All required Laravel PHP extensions are loaded.'
                    : 'Missing PHP extensions: '.implode(', ', $missingExtensions).'.',
            ),
            $this->check(
                'app.environment',
                in_array(config('app.env'), ['staging', 'production'], true),
                in_array(config('app.env'), ['staging', 'production'], true)
                    ? 'APP_ENV identifies a hosted environment.'
                    : 'APP_ENV must be staging or production.',
            ),
            $this->check(
                'app.debug_disabled',
                config('app.debug') === false,
                config('app.debug') === false ? 'APP_DEBUG is disabled.' : 'APP_DEBUG must be false.',
            ),
            $this->check(
                'app.https_url',
                parse_url($url, PHP_URL_SCHEME) === 'https',
                parse_url($url, PHP_URL_SCHEME) === 'https' ? 'APP_URL uses HTTPS.' : 'APP_URL must use HTTPS.',
            ),
            $this->check(
                'app.key_present',
                is_string(config('app.key')) && trim((string) config('app.key')) !== '',
                is_string(config('app.key')) && trim((string) config('app.key')) !== ''
                    ? 'APP_KEY is configured.'
                    : 'APP_KEY is missing.',
            ),
            $this->check(
                'simulation.mode',
                config('simulation.mode') === 'SIMULATION',
                config('simulation.mode') === 'SIMULATION'
                    ? 'APP_MODE is SIMULATION.'
                    : 'APP_MODE must be SIMULATION.',
            ),
            $this->check(
                'simulation.synthetic_only',
                config('simulation.synthetic_only') === true,
                config('simulation.synthetic_only') === true
                    ? 'Synthetic-only enforcement is enabled.'
                    : 'APP_SYNTHETIC_ONLY must be true.',
            ),
            $this->check(
                'session.secure_cookie',
                config('session.secure') === true,
                config('session.secure') === true
                    ? 'Session cookies require HTTPS.'
                    : 'SESSION_SECURE_COOKIE must be true.',
            ),
            $this->check(
                'session.encrypted',
                config('session.encrypt') === true,
                config('session.encrypt') === true
                    ? 'Session payload encryption is enabled.'
                    : 'SESSION_ENCRYPT must be true.',
            ),
            $this->check(
                'database.mysql',
                config('database.default') === 'mysql',
                config('database.default') === 'mysql'
                    ? 'The default database connection is MySQL.'
                    : 'DB_CONNECTION must be mysql for the hosted reference environment.',
            ),
            $this->check(
                'database.connectivity',
                $databaseError === null,
                $databaseError === null
                    ? 'The configured database connection opened without an application query.'
                    : 'Database connection failed with '.$databaseError.'.',
            ),
            $this->check(
                'queue.database',
                config('queue.default') === 'database',
                config('queue.default') === 'database'
                    ? 'The queue uses the database driver.'
                    : 'QUEUE_CONNECTION must be database until a supported worker backend is approved.',
            ),
            $this->check(
                'storage.private_root',
                $privateRootIsOutsidePublic,
                $privateRootIsOutsidePublic
                    ? 'The local/private filesystem root is outside public/.'
                    : 'Private storage must be outside the public document root.',
            ),
            $this->check(
                'storage.writable',
                $unwritablePaths === [],
                $unwritablePaths === []
                    ? 'Private storage, storage/, and bootstrap/cache are writable.'
                    : 'Unwritable required paths: '.implode(', ', $unwritablePaths).'.',
            ),
            $this->check(
                'health.route',
                $healthRouteExists,
                $healthRouteExists ? 'The Laravel /up health route is registered.' : 'The /up health route is missing.',
            ),
            $this->check(
                'public.build_manifest',
                is_file(public_path('build/manifest.json')),
                is_file(public_path('build/manifest.json'))
                    ? 'The production asset manifest is present.'
                    : 'Build public assets before creating a release artifact.',
            ),
        ];

        $evidencePath = $this->option('evidence');
        $evidenceDocument = null;

        if (is_string($evidencePath) && trim($evidencePath) !== '') {
            $evidenceResult = $this->loadEvidenceDocument($evidencePath);
            $evidenceDocument = $evidenceResult['document'];
            $checks[] = $this->check(
                'evidence.file',
                $evidenceResult['error'] === null,
                $evidenceResult['error'] === null
                    ? 'A valid sanitized evidence file was loaded.'
                    : $evidenceResult['error'],
            );
        }

        $manualChecks = [];

        foreach (self::MANUAL_CHECKS as $id => $instruction) {
            $entry = is_array($evidenceDocument)
                ? ($evidenceDocument['checks'][$id] ?? null)
                : null;
            $manualChecks[] = $this->manualCheck($id, $instruction, $entry);
        }

        $allChecks = [...$checks, ...$manualChecks];
        $failed = count(array_filter(
            $allChecks,
            fn (array $check): bool => $check['status'] === 'FAIL',
        ));
        $passed = count(array_filter(
            $allChecks,
            fn (array $check): bool => $check['status'] === 'PASS',
        ));
        $manualPending = count(array_filter(
            $manualChecks,
            fn (array $check): bool => $check['status'] === 'PENDING',
        ));
        $status = $failed > 0
            ? 'BLOCKED'
            : ($manualPending > 0 ? 'INCOMPLETE' : 'READY');
        $report = [
            'schemaVersion' => 1,
            'readOnly' => true,
            'status' => $status,
            'summary' => [
                'passed' => $passed,
                'failed' => $failed,
                'manualPending' => $manualPending,
            ],
            'checks' => $allChecks,
        ];

        if ($this->option('json')) {
            try {
                $this->line(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));
            } catch (JsonException $exception) {
                $this->error('The hosting preflight report could not be encoded: '.$exception->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->info('Read-only hosting preflight: '.$report['status']);
            $this->table(
                ['Check', 'Status', 'Evidence'],
                array_map(
                    fn (array $check): array => [$check['id'], $check['status'], $check['detail']],
                    $allChecks,
                ),
            );
        }

        return $status === 'READY' ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{id: string, status: 'PASS'|'FAIL', detail: string} */
    private function check(string $id, bool $passed, string $detail): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'PASS' : 'FAIL',
            'detail' => $detail,
        ];
    }

    /**
     * @return array{id: string, status: 'PASS'|'FAIL'|'PENDING', detail: string}
     */
    private function manualCheck(string $id, string $instruction, mixed $entry): array
    {
        if (! is_array($entry)) {
            return [
                'id' => $id,
                'status' => 'PENDING',
                'detail' => $instruction,
            ];
        }

        $status = $entry['status'];

        return [
            'id' => $id,
            'status' => $status,
            'detail' => $status === 'PASS'
                ? 'Sanitized evidence was recorded.'
                : ($status === 'FAIL'
                    ? 'The supplied evidence marks this check as failed.'
                    : $instruction),
        ];
    }

    /**
     * @return array{document: array<string, mixed>|null, error: string|null}
     */
    private function loadEvidenceDocument(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'document' => null,
                'error' => 'The evidence file is missing or unreadable; its path and contents were not reported.',
            ];
        }

        try {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new JsonException('Evidence could not be read.');
            }

            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'document' => null,
                'error' => 'The evidence file is not valid JSON; its contents were not reported.',
            ];
        }

        $checkedAt = is_array($document) && is_string($document['checkedAt'] ?? null)
            ? DateTimeImmutable::createFromFormat(DATE_ATOM, $document['checkedAt'])
            : false;

        if (! is_array($document)
            || ($document['schemaVersion'] ?? null) !== 1
            || ($document['target'] ?? null) !== 'hostinger-staging'
            || $checkedAt === false
            || ! is_array($document['checks'] ?? null)
        ) {
            return [
                'document' => null,
                'error' => 'The evidence file does not match schema version 1 for hostinger-staging.',
            ];
        }

        $unknownIds = array_diff(array_keys($document['checks']), array_keys(self::MANUAL_CHECKS));

        if ($unknownIds !== []) {
            return [
                'document' => null,
                'error' => 'The evidence file contains unknown check identifiers.',
            ];
        }

        foreach ($document['checks'] as $entry) {
            if (! is_array($entry)
                || ! in_array($entry['status'] ?? null, ['PASS', 'FAIL', 'PENDING'], true)
                || ! is_string($entry['evidence'] ?? null)
                || trim($entry['evidence']) === ''
            ) {
                return [
                    'document' => null,
                    'error' => 'Each supplied check requires a valid status and non-empty sanitized evidence.',
                ];
            }
        }

        $hasConclusiveEvidence = collect($document['checks'])->contains(
            fn (array $entry): bool => in_array($entry['status'], ['PASS', 'FAIL'], true),
        );
        $now = new DateTimeImmutable('now');

        if ($hasConclusiveEvidence
            && ($checkedAt < $now->modify('-'.self::CONCLUSIVE_EVIDENCE_MAX_AGE_DAYS.' days')
                || $checkedAt > $now->modify('+5 minutes'))
        ) {
            return [
                'document' => null,
                'error' => 'Conclusive evidence must be collected within the last 30 days and cannot be future-dated.',
            ];
        }

        return [
            'document' => $document,
            'error' => null,
        ];
    }
}
