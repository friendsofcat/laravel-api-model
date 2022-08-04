<?php

namespace MattaDavi\LaravelApiModel;

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
         * Adds ability to use external scopes defined within model of API provider.
         */
        Builder::macro(
            'externalScope',
            function (array|string $scope, mixed $scopeArgs = [])
            {
                $this->operators[] = 'scope';

                if (is_array($scope)) {
                    foreach ($scope as $key => $value) {
                        if (is_numeric($key)){
                            $this->where($value, 'scope', '');
                        } else {
                            $this->where($key, 'scope', $value);
                        }
                    }
                } else {
                    $this->where($scope, 'scope', $scopeArgs);
                }

                array_pop($this->operators);
            }
        );
    }
}
