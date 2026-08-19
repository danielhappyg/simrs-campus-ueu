#!/bin/sh

set -eu

cd "${APP_ROOT:-/var/www/html}"

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

runtime_user_group="${RUNTIME_USER_GROUP-www-data:www-data}"

if [ -n "$runtime_user_group" ]; then
    chown -R "$runtime_user_group" storage bootstrap/cache
fi

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction

if [ "${DEMO_SEED_ENABLED:-false}" = "true" ]; then
    if php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        exit(Illuminate\Support\Facades\DB::table("simulation_sessions")
            ->where("code", "SIM-RJ-UEU-001")
            ->exists() ? 0 : 1);
    '; then
        echo "Synthetic reference fixture already exists; seed skipped."
    else
        php artisan db:seed --force --no-interaction
    fi
fi

php artisan config:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
