<?php

namespace MattaDavi\LaravelApiModel;

use Carbon\Carbon;
use RuntimeException;
use LZCompressor\LZString;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Grammar as GrammarBase;
use Illuminate\Database\Connection as ConnectionBase;

class Connection extends ConnectionBase
{
    public const AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS = 'passport_client_credentials';
    public const MAX_URL_LENGTH = 2048;

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

        if (! $accessToken) {
            $result = Http::post($auth['url'], [
                'grant_type' => 'client_credentials',
                'client_id' => $auth['client_id'],
                'client_secret' => $auth['client_secret'],
            ])
                ->throw()
                ->json();

            $accessToken = $result['access_token'];

            // Cache the token.
            Cache::put($key, $accessToken, (int) (0.75 * $result['expires_in']));
        }

        // Add access token to headers.
        return "Authorization: Bearer ${accessToken}";
    }

    /**
     * @param string|false $query E.g. /articles?status=published
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

            // If the full URL is too long, we need to compress it.
            $url = $this->getRequestUrl($fullUrl);

            // Get rows for each partial URL.
            $result = $this->getResult($url);

            return $this->formatResult($result);
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

        if ($auth && $auth['type'] === self::AUTH_TYPE_PASSPORT_CLIENT_CREDENTIALS) {
            $headers[] = $this->getAccessTokenHeader($auth);
        }

        return Http::withHeaders($headers)
            ->get($url)
            ->throw()
            ->json();
    }

    protected function getRequestUrl($url)
    {
        $maxUrlLength = $this->getConfig('max_url_length') ?: self::MAX_URL_LENGTH;

        if (strlen($url) > $maxUrlLength) {
            // Compressing gives us roughly 15% shorter url
            $url = $this->compressLongUrl(urldecode($url));

            if (strlen($url) > $maxUrlLength) {
                throw new RuntimeException('Too long url');
            }
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

        return $data->current_page
        ? $data->data
        : $data;
    }
}
