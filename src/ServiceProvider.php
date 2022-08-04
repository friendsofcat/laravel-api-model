<?php

namespace MattaDavi\LaravelApiModel;

use RuntimeException;
use Illuminate\Database\Query\Builder;
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

        /*
         * Provides the ability to use external scopes, defined on API provider side.
         */
        Builder::macro(
            'externalScope',
            function (array|string $scope, mixed $scopeArgs = []) {
                if (! $this->grammar instanceof Grammar) {
                    throw new RuntimeException('External scopes are not supported for this query!');
                }

                if (is_array($scope)) {
                    foreach ($scope as $key => $value) {
                        if (is_numeric($key)) {
                            $this->where($value, 'scope', []);
                        } else {
                            $this->where($key, 'scope', $value);
                        }
                    }
                } else {
                    $this->where($scope, 'scope', $scopeArgs);
                }
            }
        );
    }
}
