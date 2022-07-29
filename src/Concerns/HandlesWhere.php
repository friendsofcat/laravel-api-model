<?php

namespace MattaDavi\LaravelApiModel\Concerns;

use DateTime;
use DateTimeZone;
use RuntimeException;

trait HandlesWhere
{
    public array $operatorsWithAlias = [
        '=' => 'e',
        '<' => 'lt',
        '>' => 'gt',
        '<=' => 'lte',
        '>=' => 'gte',
        '<>' => 'ne',
        '!=' => 'ne',
        '|' => 'bo',
        '^' => 'beo',
        '<<' => 'ls',
        '>>' => 'rs',
        '&' => 'ba',
        '&~' => 'bai',
        '~' => 'bi',
        '~*' => 'bim',
        '!~' => 'nbi',
        '!~*' => 'nbim',
        '~~*' => 'bibim',
        '!~~*' => 'nbibim',
    ];

    /*
     * Simple identifier incremented when accessed.
     * Used as a key prefix to ensure distinction for where types such as 'Column' or 'raw'.
     */
    private int $uniqueIdentifier = 0;

    /*
     * If query is using nested logic,
     * attach ordered 'legend' which will help to understand nested logic.
     *
     * Value example: ['and', '0:or']
     *
     * 0:or
     * ------------
     * 0    => index of parent nest in ordered legend
     * or   => logic of nest. Could be 'and' or 'or' (results of 'where(function(){})' or 'orWhere(function(){})')
     */
    protected array $nestedIds = [];

    /*
     * Index of parent for current nest from $nestedIds
     *
     * Possible values:
     * -1    => no nested logic in current query
     * <0,∞) => index of parent for current nest from $nestedIds
     */
    protected int $nestedCursor = -1;

    public function getUniqueIdentifier(): int
    {
        return $this->uniqueIdentifier++;
    }

    /*
     * Key example:
     *
     * 1:and:KEY:OPERATOR
     * ------------
     * 1        => id of nest (if nested)
     * and      => where logic. Possible values: 'and', 'or'
     * KEY      => affected column or where type prefixed with a unique id (i.e. ...where(KEY, '>', 5)...)
     * OPERATOR => operator appended if needed while handling where (i.e. '>', '<', '=', 'like'...)
     */
    protected function getKeyForWhereClause(array &$where, $assignId = false): ?string
    {
        /*
         * Some where types are not dealing with a single specific column.
         * As the result is a query string build from array, to be able to distinguish and not overwrite
         * some previous logic, we append an identifier to a key.
         */
        $key = $assignId
            ? sprintf('%s-%s', $this->getUniqueIdentifier(), strtolower($where['type']))
            : $where['column'];

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

        /*
         * When we are dealing with nested logic, the nest id is prepended to the key.
         */
        $nestedId = $this->nestedCursor >= 0 ? $this->nestedCursor : null;

        if (! is_null($nestedId)) {
            $key = sprintf('%+u:%s:%s', $nestedId, $where['boolean'], $key);
        }

        return $key;
    }

    /**
     * @param string $key
     * @param string|array|integer|null $value
     * @return mixed
     */
    private function filterKeyValue($key, $value): mixed
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

    private function formatDateTime($value, $appDtZone, $connDtZone): string
    {
        return (new DateTime($value, $appDtZone))->setTimezone($connDtZone)->format('Y-m-d H:i:s');
    }

    protected function getUrlSafeOperator($where)
    {
        if (! isset($where['operator'])) return null;

        return $this->operatorsWithAlias[$where['operator']] ?? $where['operator'];
    }

    protected function handleWheres($wheres, &$params, $nestedLevel = -1): void
    {
        foreach ($wheres as $where) {
            /*
             * Every supported whereType has its own method.
             * i.e. handleWhereBasic(...)
             */
            $whereHandler = sprintf('handleWhere%s', \Str::ucfirst($where['type']));

            if (! method_exists($this, $whereHandler)) {
                throw new RuntimeException('Unsupported query where type ' . $where['type']);
            }

            $this->{$whereHandler}($where, $params, $nestedLevel);
        }

        /*
         * If trashed logic is not specified and model is using soft deletes,
         * retrieve results with trashed. (due to JSON:API client compatibility)
         */
        if (! isset($params['trashed']) && ! is_null($this->config['soft_deletes_column'])) {
            $params['trashed'] = 'with';
        }

        /*
         * If query is using nested logic,
         * attach ordered 'legend' which will help to understand nested logic.
         *
         * Value example: and,0:and,or,2:and,2:or,4:and,4:and
         *
         * 0:and
         * ------------
         * 0    => index of parent nest in ordered legend
         * and  => logic of nest. Could be 'and' or 'or' (results of 'where(function(){})', 'orWhere(function(){})')
         */
        if (sizeof($this->nestedIds)) {
            $params['nested'] = implode($this->config['default_array_value_separator'], $this->nestedIds);
        }
    }

    private function handleWhereBasic($where, &$params): void
    {
        $param = sprintf(
            "%s:%s",
            $this->getKeyForWhereClause($where),
            str_replace(' ', '_', $this->getUrlSafeOperator($where))
        );

        $params[$param] = $this->filterKeyValue($where['column'] ?? '', $where['value']);
    }

    private function handleWhereColumn($where, &$params): void
    {
        $value = [
            $where['first'],
            $this->getUrlSafeOperator($where),
            $where['second']
        ];

        $key = $this->getKeyForWhereClause($where, true);
        $params[$key] = $this->filterKeyValue('', $value);
    }

    private function handleWhereIn($where, &$params): void
    {
        $key = $this->getKeyForWhereClause($where);
        $params[$key] = $this->filterKeyValue($where['column'] ?? '', $where['values']);
    }

    private function handleWhereInRaw($where, $params): void
    {
        $this->handleWhereIn($where, $params);
    }

    private function handleWhereBetween($where, &$params): void
    {
        $key = $this->getKeyForWhereClause($where);
        $params["$key:>"] = $this->filterKeyValue($where['column'] ?? '', $where['values'][0]);
        $params["$key:<"] = $this->filterKeyValue($where['column'] ?? '', $where['values'][1]);
    }

    private function handleWhereNull($where, &$params): void
    {
        $key = $this->getKeyForWhereClause($where);

        if ($key == $this->config['soft_deletes_column']) {
            $params["trashed"] = 0;
        } else {
            $params["$key:is_null"] = 1;
        }
    }

    private function handleWhereNotNull($where, &$params): void
    {
        $key = $this->getKeyForWhereClause($where);

        if ($key == $this->config['soft_deletes_column']) {
            $params["trashed"] = 'only';
        } else {
            $params["$key:is_not_null"] = 1;
        }
    }

    private function handleWhereNested($where, &$params, $nestedLevel, $nestedTypePostfix = ''): void
    {
        $this->nestedIds[] = $this->nestedCursor >= 0
            ? sprintf("%+u:%s%s", $this->nestedCursor, $where['boolean'], $nestedTypePostfix)
            : sprintf("%s", $where['boolean']);

        $this->nestedCursor = sizeof($this->nestedIds) - 1;

        $this->handleWheres($where['query']->wheres, $params, $nestedLevel + 1);

        $this->nestedCursor--;

        if ($nestedLevel <= 0) {
            $this->nestedCursor = 0;
        }
    }

    private function handleWhereExists($where, &$params, $nestedLevel): void
    {
        $this->handleWhereNested($where, $params, $nestedLevel, ':e');
    }

    private function handleWhereNotExists($where, &$params, $nestedLevel): void
    {
        $this->handleWhereNested($where, $params, $nestedLevel, ':ne');
    }

    private function handleWhereRaw($where, &$params): void
    {
        $key = $this->getKeyForWhereClause($where, true);
        $params[$key] = $this->filterKeyValue('', $where['sql']);
    }
}