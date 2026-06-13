<?php

declare(strict_types=1);

namespace AndyDefer\PhpServices\Tests;

use AndyDefer\PhpServices\PhpServiceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class IntegrationTestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrations();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PhpServiceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function runMigrations(): void
    {
        $migrationPath = __DIR__.'/Fixtures/migrations';

        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }
    }
}
