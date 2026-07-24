<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SafeTestDatabaseGuard;

class SafeTestDatabaseGuardTest extends TestCase
{
    private SafeTestDatabaseGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SafeTestDatabaseGuard();
    }

    public function test_safe_mysql_test_database_is_allowed(): void
    {
        $this->guard->assertSafe('testing', 'mysql', 'crm_test', '');
        $this->guard->assertSafe(
            'testing',
            'mysql',
            'crm_test',
            'mysql://test_user:test_password@127.0.0.1:3306/crm_test'
        );

        $this->addToAssertionCount(2);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'local environment' => ['local', 'mysql', 'crm_test', '', 'APP_ENV'],
            'production environment' => ['production', 'mysql', 'crm_test', '', 'APP_ENV'],
            'sqlite connection' => ['testing', 'sqlite', 'crm_test', '', 'DB_CONNECTION'],
            'empty database' => ['testing', 'mysql', '', '', 'must not be empty'],
            'production database' => ['testing', 'mysql', 'crm_db', '', 'refusing'],
            'generic crm database' => ['testing', 'mysql', 'crm', '', 'must end with'],
            'production-like name' => ['testing', 'mysql', 'production', '', 'must end with'],
            'missing test suffix' => ['testing', 'mysql', 'crm_staging', '', 'must end with'],
            'unapproved test database' => ['testing', 'mysql', 'other_test', '', 'must be "crm_test"'],
            'unsafe database URL' => [
                'testing',
                'mysql',
                'crm_test',
                'mysql://user:super-secret@127.0.0.1:3306/crm_db',
                'DB_URL',
            ],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configuration_is_rejected_without_leaking_credentials(
        string $environment,
        string $connection,
        string $database,
        string $databaseUrl,
        string $expectedMessage,
    ): void {
        try {
            $this->guard->assertSafe($environment, $connection, $database, $databaseUrl);
            $this->fail('Unsafe database configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            $this->assertStringNotContainsString('super-secret', $exception->getMessage());
        }
    }
}
