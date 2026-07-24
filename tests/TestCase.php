<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\SafeTestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $application */
        $application = parent::createApplication();

        (new SafeTestDatabaseGuard())->assertSafe(
            environment: $application->environment(),
            defaultConnection: (string) $application['config']->get('database.default'),
            database: $application['config']->get('database.connections.mysql.database'),
            databaseUrl: $application['config']->get('database.connections.mysql.url'),
        );

        return $application;
    }
}
