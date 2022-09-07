<?php

namespace FriendsOfCat\LaravelApiModel\Concerns;

trait HandlesUrlParams
{
    private array $urlParams = [];

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
            if (! isset($operator['operator'])) {
                return null;
            }

            $operator = $operator['operator'];
        }

        return str_replace(' ', '_', $this->operatorsWithAlias[$operator] ?? $operator);
    }

    protected function toQueryArray(array $value): string
    {
        return implode($this->config['array_value_separator'], $value);
    }

    public function setUrlParams(mixed $value): void
    {
        $this->urlParams = $value;
    }

    public function setUrlParam(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $value = $this->toQueryArray($value);
        }

        $this->urlParams[$key] = $value;
    }

    public function getUrlParams(): array
    {
        return $this->urlParams;
    }
}
