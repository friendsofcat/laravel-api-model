<?php

namespace FriendsOfCat\LaravelApiModel;

use RuntimeException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use FriendsOfCat\LaravelApiModel\Concerns\HandlesWhere;
use FriendsOfCat\LaravelApiModel\Concerns\HandlesUrlParams;
use Illuminate\Database\Query\Grammars\Grammar as GrammarBase;

class Grammar extends GrammarBase
{
    use HandlesWhere;
    use HandlesUrlParams;

    /*
     * Provides the ability to use external scopes, defined on API provider side.
     */
    protected $operators = ['scope'];

    public array $config = [
        'array_value_separator' => ',',
    ];

    /**
     * @param array $config
     * @return Grammar
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        $this->setUrlParams($this->config['default_params'] ?? []);

        return $this;
    }

    /**
     * @param  Builder  $query
     * @return string
     */
    public function compileExists(Builder $query): string
    {
        $this->handleQueryType('exists');

        return $this->compileSelect($query);
    }

    /**
     * @param  Builder  $query
     * @param  array  $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate): string
    {
        $query->aggregate = null;
        $this->handleQueryType($aggregate['function'], $aggregate['columns'] ?? null);

        return $this->compileSelect($query);
    }

    /**
     * @param  Builder  $query
     * @param array $params
     * @return string
     */
    protected function compileUrl(Builder $query, array $params = []): string
    {
        $url = "/{$query->from}";

        // Reset params to ensure correct re-usability of query instance.
        $this->urlParams = [];

        return empty($params)
            ? $url
            : sprintf('%s?%s', $url, Str::httpBuildQuery($params));
    }

    /**
     * @param Builder $query
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        if ($query->aggregate) {
            return $this->compileAggregate($query, $query->aggregate);
        }

        $this->handleSelect($query);
        $this->handleWheres($query->wheres);
        $this->handleOrders($query->orders);
        $this->handleLimitOffset($query);
        $this->handleGroupBy($query);
        $this->handleExternalWith($query);

        return $this->compileUrl($query, $this->getUrlParams());
    }

    /**
     * @param Builder $query
     * @return string
     */
    public function compileDelete(Builder $query): string
    {
        $this->handleQueryType('delete');
        $this->handleWheres($query->wheres);
        // todo: $this->handleJoins();

        return json_encode([
            'api_model_table' => $query->from,
            'api_query_params' => Str::buildQuery($this->getUrlParams()),
        ]);
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values): string
    {
        if (empty($values)) {
            return "{}";
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        return json_encode([
            'api_model_table' => $query->from,
            'values' => $values
        ]);
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileUpdate(Builder $query, array $values)
    {
        if (empty($values)) {
            return "{}";
        }

        $this->handleQueryType('update');
        $this->handleWheres($query->wheres);
        // todo: $this->handleJoins();

        return json_encode([
            'api_model_table' => $query->from,
            'api_query_params' => Str::buildQuery($this->getUrlParams()),
            'values' => $values,
        ]);
    }

    protected function handleExternalWith($query)
    {
        $externalWith = data_get($query->getRawBindings(), 'externalWith');

        if (is_null($externalWith)) {
            return;
        }

        /*
         * If there is a select constraint specified,
         * we must ensure it is using different separator than configured array_value_separator.
         */
        $this->setUrlParam('include', array_map(
            fn ($value) => str_replace($this->config['array_value_separator'], ':', $value),
            $externalWith
        ));
    }

    protected function handleQueryType(string $type, array $typeParams = null)
    {
        if (is_array($typeParams) && (sizeof($typeParams) > 1 || $typeParams[0] != '*')) {
            $this->setUrlParam('queryType', [$type, ...$typeParams]);
        } else {
            $this->setUrlParam('queryType', $type);
        }
    }

    protected function handleGroupBy($query)
    {
        if (is_null($query->groups)) {
            return;
        }

        $this->setUrlParam('groupBy', $query->groups);
    }

    protected function handleLimitOffset($query)
    {
        if ($query->limit) {
            $this->setUrlParam('limit', $query->limit);
        }

        if ($query->offset) {
            $this->setUrlParam('offset', $query->offset);
        }
    }

    protected function handleSelect($query)
    {
        /*
         * Early return if no custom select is specified.
         */
        if (is_null($query->columns) || (count($query->columns) == 1 && $query->columns[0] == '*')) {
            return;
        }

        $fields = [];
        $rawStatements = [];

        foreach ($query->columns as $column) {
            if (is_string($column)) {
                $fields[] = $column;
            } elseif ($column instanceof Expression) {
                $rawStatements[] = $column->getValue();
            } else {
                throw new RuntimeException('Closures in select statements are currently not supported');
                /*
                 * For possible future implementation, we can run this closure like this:
                 * $column($query->newQuery());
                 */
            }
        }

        if (count($rawStatements)) {
            $this->setUrlParam('selectRaw', $rawStatements);
        }

        $this->setUrlParam('fields', $fields);
    }

    protected function handleOrders($orders)
    {
        if (empty($orders)) {
            return;
        }

        $formattedOrders = array_map(
            fn ($order) => $this->orderToString($order),
            $orders
        );

        $this->setUrlParam('sort', $formattedOrders);
    }

    protected function orderToString(array $order): string
    {
        return $order['direction'] === 'desc'
            ? '-' . $order['column']
            : $order['column'];
    }
}
