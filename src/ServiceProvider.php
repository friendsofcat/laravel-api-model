<?php

namespace MattaDavi\LaravelApiModel;

use Illuminate\Database\Connection as ConnectionBase;
use Illuminate\Support\ServiceProvider as ServiceProviderBase;

class ServiceProvider extends ServiceProviderBase
{
    public function register()
    {
        ConnectionBase::resolverFor('laravel_api_model', static function ($connection, $database, $prefix, $config) {
            if (app()->has(Connection::class)) {
                return app(Connection::class);
            }

            return new Connection($connection, $database, $prefix, $config);
        });
    }
}
