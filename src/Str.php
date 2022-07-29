<?php

namespace MattaDavi\LaravelApiModel;

use RuntimeException;

class Str
{
    /*
     * Params with key listed in this array won't be wrapped by 'filter[]'
     */
    protected const NON_FILTER_VALUES = [
        'sort',
        'page',
        'per_page',
        'nested',
        'queryType',
        'fields',
        'selectRaw',
        'limit',
        'offset',
        'groupBy',
    ];

    /**
     * @param array $params
     * @return string
     */
    public static function httpBuildQuery(array $params): string
    {
        $query = '';
        $paramIndex = 0;

        foreach ($params as $key => $value) {
            /*
             * As everything is treated as string in URL,
             * convert bool values to integer variants.
             */
            if (is_bool($value)) $value = (int) $value;

            if (! is_string($value) && ! is_integer($value)) {
                throw new RuntimeException('Value should be a string or an integer');
            }

            if ($paramIndex++) $query .= '&';

            if ( ! in_array($key, self::NON_FILTER_VALUES)) {
                $query .= "filter[$key]=";
            } else {
                $query .= "$key=";
            }

            $query .= urlencode($value);
        }

        return $query;
    }

    /**
     * @param string $query
     * @return array
     */
    public static function parseQuery($query)
    {
        $params = [];

        foreach (explode('&', $query) as $queryPart) {
            $parts = explode('=', $queryPart);

            if (count($parts) === 2) {
                $key = $parts[0];
                $value = urldecode($parts[1]);

                if (self::startsWith($key, 'filter')) {
                    $originalKey = str_replace(['filter[',']'], '', $key);
                    $params[$originalKey][] = $value;
                } else {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @return boolean
     */
    public static function startsWith($haystack, $needle)
    {
        $length = strlen($needle);

        if ($length) {
            return (substr($haystack, $length) === $needle);
        }

        return false;
    }
}
