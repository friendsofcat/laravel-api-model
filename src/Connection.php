<?php

namespace MattaDavi\LaravelApiModel;

use Carbon\Carbon;
use RuntimeException;
use Illuminate\Support\Facades\Cache;
use \Illuminate\Support\Facades\Http;
use Illuminate\Database\Grammar as GrammarBase;
use Illuminate\Database\Connection as ConnectionBase;

class Connection extends ConnectionBase
{
    const AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS = 'passport_client_credentials';

    /**
     * @return GrammarBase
     */
    protected function getDefaultQueryGrammar()
    {
        $grammar = app(Grammar::class);
        $grammar->setConfig($this->getConfig());

        return $this->withTablePrefix($grammar);
    }

    protected function getAccessTokenHeader($auth)
    {
        $key = 'laravel-api-model|' . $this->getDatabaseName() . '|token';
        $accessToken = Cache::get($key);

        if (!$accessToken) {
            $result = Http::post($auth['url'], [
                'grant_type' => 'client_credentials',
                'client_id' => $auth['client_id'],
                'client_secret' => $auth['client_secret'],
            ])
            ->throw()
            ->json();

            $accessToken = $result['access_token'];

            // Cache the token.
            Cache::put($key, $accessToken, (int)(0.75 * $result['expires_in']));
        }

        // Add access token to headers.
        return "Authorization: Bearer $accessToken";
    }

    /**
     * @param string|false $query E.g. /articles?status=published
     * @param mixed[] $bindings
     * @param bool $useReadPdo
     * @return mixed[]
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (!$query) {
            return [];
        }

        return $this->run($query, $bindings, function ($query) {

            $fullUrl = $this->getDatabaseName() . $query;

            // If the full URL is too long, we need to split it.
            $urls = $this->getRequestUrls($fullUrl);

            // Get rows for each partial URL.
            $results = $this->getResults($urls);

            return $this->formatResults($results);
        });
    }

    protected function formatResults($results)
    {
        $appTimezone = config('app.timezone');
        $connectionTimezone = $this->getConfig('timezone');
        $configDatetimeKeys = $this->getConfig('datetime_keys');

        if (!$connectionTimezone || empty($results) || $connectionTimezone === $appTimezone || empty($configDatetimeKeys)) {
            return $results;
        }

        return $results->map(function($result) use ($configDatetimeKeys, $appTimezone) {
            foreach ($configDatetimeKeys as $key) {
                if (array_key_exists($key, $result)) {
                    $result[$key] = Carbon::parse($result[$key])->setTimezone($appTimezone);
                }
            }

            return $result;
        });
    }

    /**
     * @param string $url
     * @return array
     */
    private function fetchData($url)
    {
        $auth = $this->getConfig('auth');
        $headers = $this->getConfig('headers') ?: [];

        if ($auth && $auth['type'] === self::AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS) {
            $headers[] = $this->getAccessTokenHeader($auth);
        }

        return Http::withHeaders($headers)
            ->get($url)
            ->throw()
            ->json();
    }

    protected function getRequestUrls($url)
    {
        $maxUrlLength = $this->getConfig('max_url_length') ?: 4000;

        $urls = [$url];

        if (strlen($url) > $maxUrlLength) {
            $urls = $this->splitLongUrl($url);
        }

        return $urls;
    }

    protected function splitLongUrl($url)
    {
        // Parse query string and get params.
        $queryIndex = strpos($url, '?');

        if ($queryIndex === false) {
            throw new RuntimeException('Long URLs should have query string');
        }

        $params = Str::parseQuery(substr($url, $queryIndex + 1));

        $keyWithMostValues = collect($params)
            ->filter(fn ($value) => is_array($value))
            ->sortByDesc(fn ($value) => count($value))
            ->keys()
            ->first();

        if ($keyWithMostValues === null) {
            throw new RuntimeException('Long URLs should have at least one array in query string');
        }

        // Create partial URLs.
        $urls = [];

        foreach (array_chunk($params[$keyWithMostValues], 200) as $values) {
            $params[$keyWithMostValues] = $values;
            $urls[] = substr($url, 0, $queryIndex + 1) . Str::httpBuildQuery($params);
        }

        return $urls;
    }

    protected function getResults($urls)
    {
        $maxPerPage = $this->getConfig('default_params')['per_page'];

        $results = collect();

        foreach ($urls as $url) {
            $data = collect($this->fetchData($url));

            if ($data->current_page) {
                // There is pagination. We expect to receive data objects in the 'data' property.
                $results = $results->merge($data->data);

                // If the URL does not have the 'page' parameter, get data from all the pages.
                if (count($data->data) >= $maxPerPage && !preg_match('#(\?|&)page=\d+#', $url)) {
                    $results = $results->merge($this->getResultsFromAllPages($data, $url, $maxPerPage));
                }
            } else {
                // No pagination.
                $results = $results->merge($data);
            }
        }

        return $results;
    }

    protected function getResultsFromAllPages($data, $url, $maxPerPage)
    {
        $results = collect();
        $page = $data->current_page;
        $hasQueryString = str_contains($url, '?');

        while (count($data->data) >= $maxPerPage) {
            $page++;
            $nextUrl = $url . ($hasQueryString ? '&' : '?') . "page=$page";
            $data = collect($this->fetchData($nextUrl));
            $results = $results->merge($data->data);
        }

        return $results;
    }
}