<?php

namespace MattaDavi\LaravelApiModel\Concerns;

trait HandlesUrlParams
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

    protected function getUrlSafeOperator(string|array $operator): ?string
    {
        /*
         * Support for passing the where statement
         */
        if (is_array($operator)) {
            if (! isset($operator['operator'])) return null;

            $operator = $operator['operator'];
        }

        return str_replace(' ', '_', $this->operatorsWithAlias[$operator] ?? $operator);
    }

    protected function toQueryArray(array $value): string
    {
        return implode($this->config['default_array_value_separator'], $value);
    }
}