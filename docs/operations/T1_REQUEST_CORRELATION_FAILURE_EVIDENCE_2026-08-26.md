# T1 request-correlation failure evidence

- **Date:** 26 August 2026
- **Environment:** local Laravel feature suite on disposable SQLite
- **Scope:** HTTP request correlation, safe exception logging, authorization-denial audit continuity
- **Result:** PASS — local development evidence only

## Behavior now proven

Every web request receives an internally generated ULID in `request_id`. Normal responses expose the same value through `X-Request-Id`. When downstream middleware or a controller throws an unhandled exception, Laravel's final exception-response callback now restores the header even though the normal middleware response path never resumes.

Reportable HTTP failures create one structured `Unhandled HTTP exception.` event with this exact context:

- `request_id`;
- `exception_class`;
- a SHA-256 fingerprint derived from class, source file and line;
- HTTP method;
- route name; and
- route template.

The structured event deliberately excludes the exception message, stack trace, concrete URL, query string, request payload, user data, and route-parameter values. It replaces Laravel's duplicate default HTTP exception report so hosted stderr does not reintroduce those details.

Forbidden exception responses use a separate structured `Forbidden HTTP exception.` notice with request ID, exception class, method, route name and route template only. The same request ID is retained in the authorization-denial audit event. The former raw `error_log` message and concrete request path were removed.

Artisan remains a separate boundary. Laravel binds a synthetic Request during console bootstrap, but it has not passed through the web correlation middleware. Console exceptions without an existing web request ID therefore continue to Laravel's default reporter with their diagnostic exception context rather than being misclassified as HTTP failures.

## Automated evidence

Focused PHPUnit passed **6 tests / 17 assertions** across request-correlation and authorization-denial coverage:

1. a successful `/login` response contains a valid ULID header;
2. a test-only web route throws an unhandled `RuntimeException`;
3. the production-style 500 response contains a ULID header and does not expose the exception detail;
4. the structured 500 log contains the identical request ID and only the exact safe field set;
5. a rendered 403 response, its safe structured notice and its validated audit row share one request ID; and
6. a console exception reaches Laravel's default diagnostic logger and does not create the HTTP-safe replacement event.

The complete backend gate after this change passed Pint, PHPStan and PHPUnit with **406 tests, 404 passed, 2 expected skips and 5,060 assertions**.

## Honest remaining boundary

This is local framework-level evidence. It does not prove that Vercel's log collector retains or indexes the fields, that an alerting system uses the correlation ID, that a hosted proxy preserves the response header, or that a multi-service trace propagates it beyond this Laravel application. Those require an approved deployment and hosted observability rehearsal.

No GitHub workflow, Vercel deployment, Supabase migration or remote system was used.
