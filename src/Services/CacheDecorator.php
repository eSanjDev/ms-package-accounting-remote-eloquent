<?php

namespace Esanj\RemoteEloquent\Services;

use Esanj\RemoteEloquent\Contracts\ApiClientInterface;
use Illuminate\Support\Facades\Cache;

class CacheDecorator implements ApiClientInterface
{
    public function __construct(
        protected ApiClientInterface $client,
        protected ?string            $cacheTag = null
    )
    {
        //
    }

    public function get(string $url, array $query = [])
    {
        // Build a unique key for this request
        $cacheKey = $this->buildCacheKey($url, $query);

        return $this->cache()->remember($cacheKey, 3600, function () use ($url, $query) {
            return $this->client->get($url, $query);
        });
    }

    public function post(string $url, array $data = [])
    {
        // Potentially flush or invalidate specific cache entries
        return $this->client->post($url, $data);
    }

    public function put(string $url, array $data = [])
    {
        // Potentially flush or invalidate specific cache entries
        return $this->client->put($url, $data);
    }

    public function delete(string $url): void
    {
        // Potentially flush or invalidate specific cache entries
        $this->client->delete($url);
    }

    protected function cache()
    {
        return $this->cacheTag
            ? Cache::tags($this->cacheTag)
            : Cache::store();
    }

    protected function buildCacheKey(string $url, array $query): string
    {
        return md5($url . json_encode($query));
    }
}
