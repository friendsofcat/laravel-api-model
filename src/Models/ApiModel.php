<?php

namespace FriendsOfCat\LaravelApiModel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

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

                /*
                 * Assume it is external relation if relation method does not exist on this model instance.
                 */
                if (! method_exists($currentModel, $relationName)) {
                    return true;
                }

                $relatedClass = $currentModel->$relationName();

                if (! $relatedClass instanceof Relation) {
                    return false;
                }

                /*
                 * If connection is not explicitly set on related model,
                 * this will avoid inheriting connection from current model.
                 */
                $relatedModel = app($relatedClass->getRelated()::class);

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

    /**
     * Create a new model instance that is existing.
     *
     * @param  array  $attributes
     * @param  string|null  $connection
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = $this->newInstance([], true);

        // Ensure external eager loads are returned as models if relation is properly set.
        foreach ($attributes as $key => $value) {
            if (! method_exists($model, $key)) {
                continue;
            }

            $relation = $model->{$key}();

            if ($relation instanceof Relation && is_array($value)) {
                $formattedValues = $this->formatRelation($relation, $value);

                $model->setRelation($key, $formattedValues);
                unset($attributes[$key]);
            }
        }

        $model->setRawAttributes((array) $attributes, true);

        $model->setConnection($connection ?: $this->getConnectionName());

        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    public function formatRelation(Relation $relation, array $attributes): Collection|Model
    {
        return is_array(data_get($attributes, '0'))
            ? $this->constructCollectionOfRelations($relation, $attributes)
            : $relation->getModel()->newFromBuilder($attributes);
    }

    protected function constructCollectionOfRelations(Relation $relation, array $attributes): Collection
    {
        return $relation->getModel()->newCollection(array_map(
            fn ($values) => $relation->getModel()->newFromBuilder($values),
            $attributes
        ));
    }
}
