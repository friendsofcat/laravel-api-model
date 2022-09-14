<?php

namespace FriendsOfCat\LaravelApiModel;

use Carbon\Carbon;
use RuntimeException;
//use LZCompressor\LZString;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Grammar as GrammarBase;
use Illuminate\Database\Connection as ConnectionBase;

class Connection extends ConnectionBase
{
    public const AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS = 'passport_client_credentials';
    public const AUTH_TYPE_OAUTH = 'oauth';

    public const AUTH_TYPES = [
        self::AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS,
        self::AUTH_TYPE_OAUTH,
    ];

    public const MAX_URL_LENGTH = 2048;

    protected function getDefaultPostProcessor()
    {
        return new Processor();
    }
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

        if (! $accessToken && $auth['type'] == self::AUTH_TYPE_OAUTH) {
            $payload = [
                'grant_type' => 'client_credentials',
                'client_id' => $auth['client_id'],
                'client_secret' => $auth['client_secret'],
            ];

            $result = match ($auth['type']) {
                self::AUTH_TYPE_OAUTH => Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post($auth['url'] . '/oauth/token', $payload)
                    ->throw()
                    ->json(),
                self::AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS => Http::post($auth['url'], $payload)
                    ->throw()
                    ->json()
            };

            $accessToken = $result['access_token'];

            // Cache the token.
            Cache::put($key, $accessToken, (int) (0.75 * $result['expires_in']));
        }

        // Add access token to headers.
        return "Bearer ${accessToken}";
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function insert($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query) {
            $data = json_decode($query, true);
            $url = $this->getDatabaseName() . '/' . $data['api_model_table'];
            $result = $this->postData($url, $data['values']);

            return data_get($result, 'bool', false);
        });
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function update($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query) {
            $data = json_decode($query, true);
            $url = $this->getDatabaseName() . '/' . $data['api_model_table'];

            return $this->putData($url, $data['api_query_params'], $data['values']);
        });
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed
     */
    public function insertGetId($query, $bindings = []): mixed
    {
        return $this->run($query, $bindings, function ($query) {
            $data = json_decode($query, true);
            $url = $this->getDatabaseName() . '/' . $data['api_model_table'];
            $result = $this->postData($url, $data['values'], true);

            return data_get($result, 'id', false);
        });
    }

    /**
     * @param string|false $query
     * @param mixed[] $bindings
     * @param bool $useReadPdo
     * @return mixed[]
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (! $query) {
            return [];
        }

        return $this->run($query, $bindings, function ($query) {
            $fullUrl = $this->getDatabaseName() . $query;
            $url = $this->getRequestUrl($fullUrl);
            $result = $this->getResult($url);

            return $this->formatResult($result);
        });
    }

    /**
     * @param string|false $query
     * @param mixed[] $bindings
     * @return int
     */
    public function delete($query, $bindings = [])
    {
        if (! $query) {
            return 0;
        }

        return $this->run($query, $bindings, function ($query) {
            $data = json_decode($query, true);
            $url = $this->getDatabaseName() . '/' . $data['api_model_table'];

            return $this->deleteData($url, $data['api_query_params']);
        });
    }

    protected function formatResult($result)
    {
        $appTimezone = config('app.timezone');
        $connectionTimezone = $this->getConfig('timezone');
        $configDatetimeKeys = $this->getConfig('datetime_keys');

        if (! $connectionTimezone || empty($result) || $connectionTimezone === $appTimezone || empty($configDatetimeKeys)) {
            return $result;
        }

        return $result->map(function ($value, $key) use ($configDatetimeKeys, $appTimezone) {
            return in_array($key, $configDatetimeKeys)
                ? Carbon::parse($value)->setTimezone($appTimezone)
                : $value;
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

        if ($auth && in_array($auth['type'], self::AUTH_TYPES)) {
            $headers['Authorization'] = $this->getAccessTokenHeader($auth);
        }

        return Http::withHeaders($headers)
            ->get($url)
            ->throw()
            ->json();
    }

    /**
     * @param string $url
     * @param array $data
     * @param bool $getId
     * @return array
     */
    private function postData($url, $data, $getId = false)
    {
        $auth = $this->getConfig('auth');
        $headers = $this->getConfig('headers') ?: [];

        if ($auth && in_array($auth['type'], self::AUTH_TYPES)) {
            $headers['Authorization'] = $this->getAccessTokenHeader($auth);
        }

        return Http::withHeaders($headers)
            ->post($url, [
                'getId' => $getId,
                'data' => $data,
            ])
            ->throw()
            ->json();
    }

    /**
     * @param string $url
     * @param array $params
     * @param array $data
     * @return array
     */
    private function putData($url, $params, $data)
    {
        $auth = $this->getConfig('auth');
        $headers = $this->getConfig('headers') ?: [];

        if ($auth && in_array($auth['type'], self::AUTH_TYPES)) {
            $headers['Authorization'] = $this->getAccessTokenHeader($auth);
        }

        return Http::withHeaders($headers)
            ->put($url, [
                'params' => $params,
                'data' => $data,
            ])
            ->throw()
            ->json();
    }

    /**
     * @param string $url
     * @param array $params
     * @return array
     */
    private function deleteData($url, $params)
    {
        $auth = $this->getConfig('auth');
        $headers = $this->getConfig('headers') ?: [];

        if ($auth && in_array($auth['type'], self::AUTH_TYPES)) {
            $headers['Authorization'] = $this->getAccessTokenHeader($auth);
        }

        return Http::withHeaders($headers)
            ->delete($url, $params)
            ->throw()
            ->json();
    }

    protected function getRequestUrl($url)
    {
        $maxUrlLength = $this->getConfig('max_url_length') ?: self::MAX_URL_LENGTH;

        if (strlen($url) > $maxUrlLength) {
            // Compressing gives us roughly 15% shorter url
//            $url = $this->compressLongUrl(urldecode($url));

//            if (strlen($url) > $maxUrlLength) {
            throw new RuntimeException('Too long url');
//            }
        }

        return $url;
    }

    protected function compressLongUrl($url): string
    {
        $queryIndex = strpos($url, '?');

        if ($queryIndex === false) {
            throw new RuntimeException('Long URLs should have query string');
        }

        $baseUrl = substr($url, 0, $queryIndex);
        $compressedParams = LZString::compressToEncodedURIComponent(substr($url, $queryIndex + 1));
        $compressedUrl = sprintf('%s%s%s', $baseUrl, '?compressed=', $compressedParams);

        return $compressedUrl;
    }

    protected function getResult($url)
    {
        $data = collect($this->fetchData($url));

        return isset($data->current_page)
            ? $data->data
            : $data;
    }
}
