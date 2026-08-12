<?php

namespace Tests\Feature\Console;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Artisan;
use ReflectionProperty;
use Tests\TestCase;

class DatabaseDestructiveCommandGuardTest extends TestCase
{
    /** @var array<string, class-string> */
    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh' => FreshCommand::class,
        'migrate:refresh' => RefreshCommand::class,
        'migrate:reset' => ResetCommand::class,
        'migrate:rollback' => RollbackCommand::class,
        'db:wipe' => WipeCommand::class,
    ];

    public function test_local_environment_prohibits_all_destructive_commands(): void
    {
        $this->configureGuard('local', false, 'crm_db');

        foreach (array_keys(self::DESTRUCTIVE_COMMANDS) as $command) {
            $this->assertCommandIsProhibited($command);
        }
    }

    public function test_production_environment_prohibits_all_destructive_commands(): void
    {
        $this->configureGuard('production', true, 'crm_db');

        foreach (array_keys(self::DESTRUCTIVE_COMMANDS) as $command) {
            $this->assertCommandIsProhibited($command);
        }
    }

    public function test_testing_environment_allows_destructive_workflow_only_for_isolated_database(): void
    {
        $this->configureGuard('testing', false, 'crm_test');

        foreach (self::DESTRUCTIVE_COMMANDS as $commandClass) {
            $this->assertFalse($this->isCommandProhibited($commandClass));
        }

        $this->configureGuard('testing', false, 'crm_db');

        foreach (self::DESTRUCTIVE_COMMANDS as $commandClass) {
            $this->assertTrue($this->isCommandProhibited($commandClass));
        }
    }

    public function test_local_manual_opt_in_disables_guard_but_production_cannot_override_it(): void
    {
        $this->configureGuard('local', true, 'crm_db');

        foreach (self::DESTRUCTIVE_COMMANDS as $commandClass) {
            $this->assertFalse($this->isCommandProhibited($commandClass));
        }

        $this->configureGuard('production', true, 'crm_db');

        foreach (self::DESTRUCTIVE_COMMANDS as $commandClass) {
            $this->assertTrue($this->isCommandProhibited($commandClass));
        }
    }

    public function test_non_destructive_migrate_remains_allowed_in_isolated_testing_environment(): void
    {
        $this->configureGuard('testing', false, 'crm_test');

        $this->assertSame(0, Artisan::call('migrate', ['--pretend' => true]));
    }

    private function configureGuard(string $environment, bool $allowDestructive, string $database): void
    {
        $this->app->offsetSet('env', $environment);
        config([
            'database.allow_destructive_commands' => $allowDestructive,
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $database,
        ]);

        (new AppServiceProvider($this->app))->boot();
    }

    private function assertCommandIsProhibited(string $command): void
    {
        $exitCode = Artisan::call($command, ['--force' => true]);

        $this->assertSame(1, $exitCode, $command);
        $this->assertStringContainsString('prohibited', Artisan::output());
    }

    /** @param class-string $commandClass */
    private function isCommandProhibited(string $commandClass): bool
    {
        $property = new ReflectionProperty($commandClass, 'prohibitedFromRunning');
        $property->setAccessible(true);

        return (bool) $property->getValue();
    }
}
