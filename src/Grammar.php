<?php

namespace MattaDavi\LaravelApiModel;

use RuntimeException;
use Illuminate\Database\Query\Builder;
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
        $this->config = $config;

        foreach (self::CONFIG_DEFAULTS as $configKey => $defaultValue) {
            if(! data_get($this->config, $configKey, false)) {
                $this->config[$configKey] = $defaultValue;
            }
        }

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

        $this->handleWheres($query->wheres, $params);
        $this->handleOrders($query->orders, $params);

        if ($query->limit) {
            if ($query->limit >= $params['per_page']) {
                throw new RuntimeException('Query limit should be less than ' . $params['per_page']);
            }

            $params['per_page'] = $query->limit;
        }

        return $this->compileUrl($query, $params);
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
