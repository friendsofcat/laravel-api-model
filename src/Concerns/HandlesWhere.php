<?php

namespace FriendsOfCat\LaravelApiModel\Concerns;

use DateTime;
use DateTimeZone;
use RuntimeException;

trait HandlesWhere
{
    use HandlesUrlParams;

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
     *             Check $operatorsWithAlias in HandlesUrlParams trait
     */
    protected function getKeyForWhereClause(array &$where, $assignId = false): ?string
    {
        /*
         * Some where types are not dealing with a single specific column.
         * As the result is a query string build from array, to be able to distinguish and not overwrite
         * some previous logic, we append an identifier to a key.
         */
        $key = $assignId
            ? sprintf('%s:%s-%s', $where['boolean'], $this->getUniqueIdentifier(), strtolower($where['type']))
            : sprintf('%s:%s', $where['boolean'], $where['column']);

        // todo: check if this is necessary
        $dotIndex = strrpos($key, '.');

        if ($dotIndex !== false) {
            $key = sprintf('%s:%s', $where['boolean'], substr($key, $dotIndex + 1));

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
            $key = sprintf('%+u:%s', $nestedId, $key);
        }

        return $key;
    }

    /**
     * @param string $key
     * @param string|array|integer|null $value
     * @param bool $parseNested
     * @return mixed
     */
    private function filterKeyValue($key, $value, $parseNested = false): mixed
    {
        $connTimezone = $this->config['timezone'] ?? null;

        if ($connTimezone && in_array($key, $this->config['datetime_keys'])) {
            $value = $this->convertTimezone($value, $connTimezone);
        }

        if (is_array($value)) {
            $value = $this->toQueryArray($value, $parseNested);
        }

        return $value;
    }

    private function convertTimezone($value, $connTimezone)
    {
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

        return $value;
    }

    private function formatDateTime($value, $appDtZone, $connDtZone): string
    {
        return (new DateTime($value, $appDtZone))->setTimezone($connDtZone)->format('Y-m-d H:i:s');
    }

    protected function handleWheres($wheres, $nestedLevel = -1): void
    {
        foreach ($wheres as $where) {
            /*
             * Every supported whereType has its own method.
             * i.e. handleWhereBasic(...)
             */
            $whereHandler = 'handleWhere' . $where['type'];

            if (! method_exists($this, $whereHandler)) {
                throw new RuntimeException('Unsupported query where type ' . $where['type']);
            }

            $this->$whereHandler($where, $nestedLevel);
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
            $this->setUrlParam('nested', $this->nestedIds);
        }
    }

    private function handleWhereBasic($where): void
    {
        $key = sprintf(
            '%s:%s',
            $this->getKeyForWhereClause($where),
            $this->getUrlSafeOperator($where)
        );

        /*
         * Prep work for possible need of type change due to lazy load issue.
         */
        if ($where['type'] == 'In') {
            $this->handleWhereIn($where);
        } else {
            $this->setUrlParam($key, $this->filterKeyValue($where['column'] ?? '', $where['value'], $where['operator'] == 'scope'));
        }
    }

    private function handleWhereColumn($where): void
    {
        $value = [
            $where['first'],
            $this->getUrlSafeOperator($where),
            $where['second'],
        ];

        $key = $this->getKeyForWhereClause($where, true);
        $this->setUrlParam($key, $this->filterKeyValue('', $value));
    }

    private function handleWhereIn($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:in", $this->filterKeyValue($where['column'] ?? '', $where['values']));
    }

    private function handleWhereInRaw($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:in_raw", $this->filterKeyValue($where['column'] ?? '', $where['values']));
    }

    private function handleWhereNotIn($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:not_in", $this->filterKeyValue($where['column'] ?? '', $where['values']));
    }

    private function handleWhereNotInRaw($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:not_in_raw", $this->filterKeyValue($where['column'] ?? '', $where['values']));
    }

    private function handleWhereBetween($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:gt", $this->filterKeyValue($where['column'] ?? '', $where['values'][0]));
        $this->setUrlParam("${key}:lt", $this->filterKeyValue($where['column'] ?? '', $where['values'][1]));
    }

    private function handleWhereNotBetween($where): void
    {
        $value = [
            $this->filterKeyValue($where['column'] ?? '', $where['values'][0]),
            $this->filterKeyValue($where['column'] ?? '', $where['values'][1]),
        ];
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:not_between", $value);
    }

    private function handleWhereNull($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:is_null", 1);
    }

    private function handleWhereNotNull($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $this->setUrlParam("${key}:is_not_null", 1);
    }

    private function handleWhereFullText($where): void
    {
        throw new RuntimeException('Where type Fulltext is not currently supported for API requests!');
//        $key = $this->getKeyForWhereClause($where);
//        $this->setUrlParam("${key}:fulltext", $this->filterKeyValue($where['column'] ?? '', $where['value']));
    }

    private function handleWhereDate($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $operator = $this->getUrlSafeOperator($where);

        $this->setUrlParam("${key}:${$operator}:date", $this->filterKeyValue($where['column'] ?? '', $where['value']));
    }

    private function handleWhereDay($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $operator = $this->getUrlSafeOperator($where);

        $this->setUrlParam("${key}:${$operator}:day", $this->filterKeyValue($where['column'] ?? '', $where['value']));
    }

    private function handleWhereYear($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $operator = $this->getUrlSafeOperator($where);

        $this->setUrlParam("${key}:${$operator}:year", $this->filterKeyValue($where['column'] ?? '', $where['value']));
    }

    private function handleWhereTime($where): void
    {
        $key = $this->getKeyForWhereClause($where);
        $operator = $this->getUrlSafeOperator($where);

        $this->setUrlParam("${key}:${$operator}:time", $this->filterKeyValue($where['column'] ?? '', $where['value']));
    }

    private function handleWhereNested($where, $nestedLevel, $nestedTypePostfix = ''): void
    {
        $this->nestedIds[] = $this->nestedCursor >= 0
            ? sprintf('%+u:%s%s', $this->nestedCursor, $where['boolean'], $nestedTypePostfix)
            : sprintf('%s', $where['boolean']);

        $this->nestedCursor = sizeof($this->nestedIds) - 1;

        $this->handleWheres($where['query']->wheres, $nestedLevel + 1);

        $this->nestedCursor--;

        if ($nestedLevel <= 0) {
            $this->nestedCursor = 0;
        }
    }

    private function handleWhereExists($where, $nestedLevel): void
    {
        $this->handleWhereNested($where, $nestedLevel, ':e');
    }

    private function handleWhereNotExists($where, $nestedLevel): void
    {
        $this->handleWhereNested($where, $nestedLevel, ':ne');
    }

    private function handleWhereRaw($where): void
    {
        $key = $this->getKeyForWhereClause($where, true);
        $this->setUrlParam($key, $this->filterKeyValue('', $where['sql']));
    }
}
