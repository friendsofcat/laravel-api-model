<?php

namespace MattaDavi\LaravelApiModel;

use DateTime;
use DateTimeZone;
use RuntimeException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as GrammarBase;

class Grammar extends GrammarBase
{
    private $config = [];

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
     * @param Builder $query
     * @return string|false
     */
    public function compileSelect(Builder $query): string
    {
        $params = $this->config['default_params'] ?? [];

        $this->handleWheres($query->wheres, $params);
        $this->handleOrders($query->orders, $params);


        if ($query->limit) {
            if ($query->limit >= $params['per_page']) {
                throw new RuntimeException('Query limit should be less than ' . $params['per_page']);
            }
            $params['per_page'] = $query->limit;
        }

        $url = "/{$query->from}";

        if (! empty($params)) {
            $url .= '?';
            $queryStr = Str::httpBuildQuery($params);

            if ($queryStr === false) {
                return false;
            }
            $url .= $queryStr;
        }

        dd($url);

        return $url;
    }

    protected function handleWheres($wheres, &$params, $nestedLevel = 0, $nestedBoolean = 'and')
    {
        foreach ($wheres as $where) {
            $key = $this->getKeyForWhereClause($where, $nestedLevel, $nestedBoolean);

            // Check where type.
            switch ($where['type']) {
                case 'Basic':
                    $param = match ($where['operator']) {
                        '=' => $key,
                        '>=' => "{$key}_from_including",
                        '<=' => "{$key}_to_including",
                        '>' => "{$key}_from",
                        '<' => "{$key}_to",
                        'like' => "{$key}_like",
                        default => throw new RuntimeException('Unsupported query where operator ' . $where['operator']),
                    };
                    $params[$param] = $this->filterKeyValue($key, $where['value']);

                    break;

                case 'In':
                case 'InRaw':
                    $params[$key] = $this->filterKeyValue($key, $where['values']);

                    break;

                case 'between':
                    $params["{$key}_from"] = $this->filterKeyValue($key, $where['values'][0]);
                    $params["{$key}_to"] = $this->filterKeyValue($key, $where['values'][1]);

                    break;

                case 'Null':
                    if ($key == $this->config['soft_deletes_column']) {
                        $params["trashed"] = 0;
                    } else {
                        $params["{$key}_is_null"] = 1;
                    }

                    break;

                case 'NotNull':
                    if ($key == $this->config['soft_deletes_column']) {
                        $params["trashed"] = 'only';
                    } else {
                        $params["{$key}_is_not_null"] = 1;
                    }

                    break;

                case 'Nested':
                    $this->handleWheres($where['query']->wheres, $params, ++$nestedLevel, $where['boolean']);
                    break;

                default:
                    throw new RuntimeException('Unsupported query where type ' . $where['type']);
            }

            if (! isset($params['trashed']) && ! is_null($this->config['soft_deletes_column'])) {
                $params['trashed'] = 'with';
            }
        }
    }

    protected function handleOrders($orders, &$params)
    {
        if (! empty($orders)) {
            $params['sort'] = [];

            foreach ($orders as $order) {
                $params['sort'][] = ($order['direction'] === 'desc')
                    ? '-' . $order['column']
                    : $order['column'];
            }

            $params['sort'] = implode($this->config['default_array_value_separator'], $params['sort']);
        }
    }

    protected function getKeyForWhereClause(array &$where, int $nestedLevel, string $nestedBoolean): ?string
    {
        if ($where['type'] == 'Nested') return null;

        // Get key and strip table name.
        $key = $where['column'];
        $dotIndex = strrpos($key, '.');

        if ($dotIndex !== false) {
            $key = substr($key, $dotIndex + 1);

            // If the key has dot and type = 'Basic', we need to change type to 'In'.
            // This fixes lazy loads.
            if ($where['type'] === 'Basic') {
                $where['type'] = 'In';
                $where['values'] = [$where['value']];
                unset($where['value']);
            }
        }

        if ($nestedLevel) {
            $key = sprintf('%+u-%s:%s:%s', $nestedLevel, $nestedBoolean, $where['boolean'], $key);
        }

        return $key;
    }

    /**
     * @param string $key
     * @param string|array|integer|null $value
     * @return mixed
     */
    private function filterKeyValue($key, $value)
    {
        // Convert timezone.
        $connTimezone = $this->config['timezone'] ?? null;

        if ($connTimezone && in_array($key, $this->config['datetime_keys'])) {
            $connDtZone = new DateTimeZone($connTimezone);
            $appDtZone = new DateTimeZone(config('app.timezone'));

            if (is_string($value) && strlen($value) === 19) {
                $value = $this->formatDateTime($value, $appDtZone, $connDtZone);
            } elseif (is_array($value)) {
                $value = array_map(function ($value) use ($connDtZone, $appDtZone) {
                    return is_string($value) && strlen($value) === 19
                        ? $this->formatDateTime($value, $appDtZone, $connDtZone)
                        : $value;
                }, $value);
            }
        }

        if (is_array($value)) {
            $value = implode($this->config['default_array_value_separator'], $value);
        }

        return $value;
    }

    private function formatDateTime($value, $appDtZone, $connDtZone)
    {
        return (new DateTime($value, $appDtZone))->setTimezone($connDtZone)->format('Y-m-d H:i:s');
    }
}
