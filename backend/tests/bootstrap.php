<?php

/**
 * Custom PHPUnit bootstrap. Exists solely to close a gap in phpunit.xml's <env force="true">
 * overrides: PHPUnit applies those to $_ENV and putenv() before this file runs, but does NOT
 * reliably override $_SERVER on every PHP build/SAPI configuration, and Laravel's environment
 * detection (Illuminate\Foundation\Application::detectEnvironment(), and env()/Env::get()
 * generally) checks $_SERVER ahead of $_ENV in its adapter chain. Since this project's
 * docker-compose.yml sets `env_file: ./backend/.env` on the `app` service, APP_ENV=local /
 * DB_CONNECTION=pgsql / etc. are already present in $_SERVER for any `docker compose exec app
 * php artisan test` invocation (exec inherits the container's process environment) — without
 * this sync, Laravel would still resolve to the real "local" environment and the real "pgsql"
 * connection during tests despite phpunit.xml clearly saying "testing"/"sqlite", and
 * RefreshDatabase-based tests would silently migrate:fresh the actual dev database. Confirmed
 * experimentally while implementing Sprint 2's BOQ API — see phpunit.xml's <php> block comment
 * for the full incident writeup.
 */
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
