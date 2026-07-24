<?php

namespace Tests\Support;

use RuntimeException;

final class SafeTestDatabaseGuard
{
    private const EXPECTED_DATABASE = 'crm_test';

    public function assertSafe(
        string $environment,
        string $defaultConnection,
        mixed $database,
        mixed $databaseUrl = null,
    ): void {
        if ($environment !== 'testing') {
            throw new RuntimeException(
                'Unsafe test database configuration: APP_ENV must be "testing".'
            );
        }

        if ($defaultConnection !== 'mysql') {
            throw new RuntimeException(
                'Unsafe test database configuration: DB_CONNECTION must be "mysql".'
            );
        }

        $databaseName = trim((string) $database);

        if ($databaseName === '') {
            throw new RuntimeException(
                'Unsafe test database configuration: DB_DATABASE must not be empty.'
            );
        }

        if ($databaseName === 'crm_db') {
            throw new RuntimeException(
                'Unsafe test database configuration: refusing to run tests against "crm_db".'
            );
        }

        if (!str_ends_with($databaseName, '_test')) {
            throw new RuntimeException(
                'Unsafe test database configuration: DB_DATABASE must end with "_test".'
            );
        }

        if ($databaseName !== self::EXPECTED_DATABASE) {
            throw new RuntimeException(
                'Unsafe test database configuration: DB_DATABASE must be "crm_test".'
            );
        }

        $this->assertSafeDatabaseUrl($databaseUrl);
    }

    private function assertSafeDatabaseUrl(mixed $databaseUrl): void
    {
        $url = trim((string) $databaseUrl);

        if ($url === '') {
            return;
        }

        $urlDatabase = basename((string) parse_url($url, PHP_URL_PATH));

        if ($urlDatabase !== self::EXPECTED_DATABASE) {
            throw new RuntimeException(
                'Unsafe test database configuration: DB_URL must target "crm_test".'
            );
        }
    }
}
