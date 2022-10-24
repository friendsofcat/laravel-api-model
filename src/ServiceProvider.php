<?php

namespace FriendsOfCat\LaravelApiModel;

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
            function (array|string $scope, ...$scopeArgs) {
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

        /*
         * Provides the ability to eager load external relations, defined on API provider side.
         */
        Builder::macro(
            'externalWith',
            function (array|string $with) {
                if (! $this->grammar instanceof Grammar) {
                    throw new RuntimeException('External eager loading is not supported for this query!');
                }

                $relations = [];

                if (is_array($with)) {
                    foreach ($with as $key => $value) {
                        if (! is_numeric($key)) {
                            throw new RuntimeException('Constrained externalWith is currently not supported!');
                        }

                        $relations[] = $value;
                    }
                } else {
                    $relations[] = $with;
                }

                $this->bindings['externalWith'] = $relations;
            }
        );
    }
}
