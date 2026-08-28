<?php

namespace App\Support\Http;

final class DeploymentHostBoundary
{
    /** @return list<string> */
    public static function patterns(): array
    {
        $candidates = [
            parse_url((string) config('app.url'), PHP_URL_HOST),
            getenv('VERCEL_URL'),
            getenv('VERCEL_BRANCH_URL'),
            getenv('VERCEL_PROJECT_PRODUCTION_URL'),
            getenv('RENDER_EXTERNAL_HOSTNAME'),
        ];

        $patterns = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $host = strtolower(rtrim(trim($candidate), '.'));

            if ($host === ''
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                continue;
            }

            $patterns[] = '^'.preg_quote($host, '/').'$';
        }

        return array_values(array_unique($patterns)) ?: ['(?!)'];
    }
}
