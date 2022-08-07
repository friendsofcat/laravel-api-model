<?php

namespace MattaDavi\LaravelApiModel\Models;

use Illuminate\Database\Eloquent\Model;

abstract class ApiModel extends Model
{
    /**
     * Ability to eager load relations with same origin.
     *
     * @param  array|string  $relations
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function with($relations)
    {
        $formattedRelations = is_string($relations) ? func_get_args() : $relations;
        $sameOriginRelations = array_filter(
            $formattedRelations,
            function ($relation) {
                $currentModel = app(static::class);
                $relationName = static::resolveRelationNameFromString($relation);

                if (! method_exists($currentModel, $relationName)) {
                    return true;
                }

                /*
                 * If connection is not explicitly set on related model,
                 * this will avoid inheriting connection from current model.
                 */
                $relatedModel = app($currentModel->$relationName()->getRelated()::class);

                return $relatedModel->getConnectionName() === $currentModel->getConnectionName();
            }
        );

        if (count($sameOriginRelations)) {
            $formattedRelations = array_filter(
                $formattedRelations,
                fn ($relation) => ! in_array($relation, $sameOriginRelations)
            );

            return static::query()->with($formattedRelations)->externalWith($sameOriginRelations);
        }

        return static::query()->with($formattedRelations);
    }

    /*
     * Support for nested relations and select constrains.
     *
     * Example: 'user:id,created_at' or 'user.car.insurance'
     * ----------
     * Both examples are resolved as 'user'.
     */
    public static function resolveRelationNameFromString(string $relation): string
    {
        $relations = explode('.', $relation);

        return count($relations) > 1
            ? $relations[0]
            : explode(':', $relation)[0];
    }
}
