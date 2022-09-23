<?php

namespace Tests;

use FriendsOfCat\LaravelApiModel\ServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/migrations');
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'local_db');

        $app['config']->set('database.connections.api_model', [
            'driver' => 'laravel_api_model',
            'database' => 'http://laravel-api-model-example.test/api/model',
            'array_value_separator' => ',',
            'max_url_length' => 2048,  // default: 2048
            'datetime_keys' => ['created_at', 'updated_at', 'start_time', 'end_time'],
        ]);

        $app['config']->set('database.connections.local_db', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.debug', env('APP_DEBUG', true));

        $app['config']->set('cache.default', 'array');
    }
}