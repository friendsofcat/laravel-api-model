<?php

namespace MattaDavi\LaravelApiModel;

use RuntimeException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use MattaDavi\LaravelApiModel\Concerns\HandlesWhere;
use Illuminate\Database\Query\Grammars\Grammar as GrammarBase;

class Grammar extends GrammarBase
{
    use HandlesWhere;

    private array $config = [];

    private const CONFIG_DEFAULTS = [
        'default_array_value_separator' => ',',
        'soft_deletes_column' => null,
    ];

    /**
     * @param array $config
     * @return Grammar
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge(self::CONFIG_DEFAULTS, $config);

        return $this;
    }

    /**
     * @param  Builder  $query
     * @return string
     */
    public function compileExists(Builder $query): string
    {
        $urlQuery = $this->compileSelect($query);

        $queryType = str_contains($urlQuery, '?')
            ? '&queryType=exists'
            : '?queryType=exists';

        return $urlQuery . $queryType;
    }

    /**
     * @param  Builder  $query
     * @param  array  $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate): string
    {
        $query->aggregate = null;
        $urlQuery = $this->compileSelect($query);

        $queryType = str_contains($urlQuery, '?')
            ? sprintf('&queryType=%s', $aggregate['function'])
            : sprintf('?queryType=%s', $aggregate['function']);

        if (isset($aggregate['columns'])) {
            $columns = implode($this->config['default_array_value_separator'], $aggregate['columns']);
            $queryType .= sprintf(',%s', $columns);
        }

        return $urlQuery . $queryType;
    }

    /**
     * @param  Builder  $query
     * @param array $params
     * @return string
     */
    protected function compileUrl(Builder $query, array $params = []): string
    {
        $url = "/{$query->from}";

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

        $params = $this->config['default_params'] ?? [];

        $this->handleSelect($query, $params);
        $this->handleWheres($query->wheres, $params);
        $this->handleOrders($query->orders, $params);
        $this->handleLimitOffset($query, $params);

        return $this->compileUrl($query, $params);
    }

    protected function handleLimitOffset($query, &$params)
    {
        if ($query->limit) {
            $params['limit'] = $query->limit;
        }

        if ($query->offset) {
            $params['offset'] = $query->offset;
        }
    }

    protected function handleSelect($query, &$params)
    {
        /*
         * Early return if no custom select is specified.
         */
        if (count($query->columns) == 1 && $query->columns[0] == '*') return;

        $fields = [];
        $rawStatements = [];

        foreach ($query->columns as $column) {
            if (is_string($column)) {
                $fields[] = $column;
            } else if ($column instanceof Expression){
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
            $params['selectRaw'] = implode($this->config['default_array_value_separator'], $rawStatements);
        }

        $params['fields'] = implode($this->config['default_array_value_separator'], $fields);
    }

    protected function handleOrders($orders, &$params)
    {
        if (empty($orders)) return;

        $params['sort'] = [];

        foreach ($orders as $order) {
            $params['sort'][] = ($order['direction'] === 'desc')
                ? '-' . $order['column']
                : $order['column'];
        }

        $params['sort'] = implode($this->config['default_array_value_separator'], $params['sort']);
    }
}
