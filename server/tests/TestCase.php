<?php

namespace Fleetbase\FleetOps\Tests;

use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run package migrations if needed
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            FleetOpsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Use SQLite memory database for tests
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        
        // Set any package-specific config
        $app['config']->set('fleetops.imports.chunk_size', 100);
        $app['config']->set('fleetops.imports.max_file_size', 10240);
        
        // Additional configurations for testing
        $app['config']->set('app.key', 'base64:H+KkNb2Ir9R8cTRqaQO3nkJrpY+zGJA/bSq5gPkqwDE=');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
    }
}