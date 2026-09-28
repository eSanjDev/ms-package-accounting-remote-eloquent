<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Access;

use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\RemoteEloquentServiceProvider;
use Esanj\RemoteEloquent\Transport\RateLimitSnapshot;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What this application may do, read once and shared.
 */
final class RemoteAccess
{
    private static ?AccessSnapshot $snapshot = null;

    private static bool $resolved = false;

    /**
     * A version mismatch or a denial was seen; the next read re-fetches.
     */
    private static bool $stale = false;

    /**
     * The one automatic re-read this process is allowed.
     */
    private static bool $rereadUsed = false;

    private static bool $faking = false;

    private static ?RateLimitSnapshot $quota = null;

    private static ?int $failedAt = null;

    private static ?int $loadedAt = null;

    private const RETRY_AFTER_FAILURE_SECONDS = 10;

    /**
     * Whether a permission is published.
     */
    public static function can(string $permission): bool
    {
        $snapshot = self::snapshot();

        return $snapshot->isKnown() ? $snapshot->can($permission) : true;
    }

    public static function cannot(string $permission): bool
    {
        return ! self::can($permission);
    }

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return self::snapshot()->permissions();
    }

    /**
     * Whether one operation on one resource is published.
     */
    public static function allows(string $resource, string $operation): bool
    {
        $snapshot = self::snapshot();

        return $snapshot->isKnown() ? $snapshot->allows($resource, $operation) : true;
    }

    /**
     * The rate-limit budget as of the last response — and it sends nothing.
     *
     * @return array<string, mixed>
     */
    public static function quota(): array
    {
        if (self::$quota !== null && ! self::$quota->isEmpty()) {
            return self::$quota->toArray();
        }

        return self::$snapshot?->quota() ?? [];
    }

    /**
     * Drop every copy and read it again now.
     */
    public static function refresh(): void
    {
        if (self::$faking) {
            return;
        }

        self::forgetCache();

        self::$stale = false;
        self::$rereadUsed = false;

        self::load(true);
    }

    /**
     * Pin a permission set for a test.
     *
     * @param  array<array-key, mixed>  $permissions
     */
    public static function fake(array $permissions): void
    {
        self::$faking = true;
        self::$resolved = true;
        self::$stale = false;
        self::$rereadUsed = false;
        self::$snapshot = AccessSnapshot::fake($permissions);
    }

    /**
     * Forget everything, including the fake and the shared entry.
     */
    public static function forget(): void
    {
        self::forgetCache();

        self::$snapshot = null;
        self::$resolved = false;
        self::$stale = false;
        self::$rereadUsed = false;
        self::$faking = false;
        self::$quota = null;
        self::$failedAt = null;
        self::$loadedAt = null;
    }

    /**
     * The current snapshot, fetching it if this process has not read one.
     */
    public static function snapshot(): AccessSnapshot
    {
        if (self::$faking) {
            return self::$snapshot ?? AccessSnapshot::unknown();
        }

        if (self::$stale) {
            self::$stale = false;

            if (! self::$rereadUsed) {
                self::$rereadUsed = true;

                return self::load(true);
            }
        }

        if (self::$resolved && ! self::expired()) {
            return self::$snapshot ?? AccessSnapshot::unknown();
        }

        if (self::$failedAt !== null && Carbon::now()->getTimestamp() - self::$failedAt < self::RETRY_AFTER_FAILURE_SECONDS) {
            return self::$snapshot ?? AccessSnapshot::unknown();
        }

        return self::load(false);
    }

    /**
     * What a response said, on its way past.
     */
    public static function observe(?string $permissionsVersion, ?RateLimitSnapshot $rateLimit = null): void
    {
        if ($rateLimit !== null && ! $rateLimit->isEmpty()) {
            self::$quota = $rateLimit;
        }

        if (self::$faking || $permissionsVersion === null || $permissionsVersion === '') {
            return;
        }

        $known = self::$snapshot?->permissionsVersion();

        if ($known === null || $known === $permissionsVersion) {
            return;
        }

        self::invalidate();
    }

    /**
     * A 403 permission_denied came back.
     */
    public static function denied(): void
    {
        if (self::$faking) {
            return;
        }

        self::invalidate();
    }

    private static function invalidate(): void
    {
        self::$stale = true;

        self::forgetCache();
    }

    /**
     * @param  bool  $fresh  skip the shared entry and go to the server
     */
    private static function load(bool $fresh): AccessSnapshot
    {
        self::$resolved = true;

        $cache = self::cache();
        $key = self::cacheKey();

        if (! $fresh && $cache !== null) {
            $cached = self::read($cache, $key);

            if ($cached !== null) {
                self::$loadedAt = is_int($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : Carbon::now()->getTimestamp();

                return self::$snapshot = AccessSnapshot::fromArray($cached);
            }
        }

        $transport = self::transport();

        if ($transport === null) {
            return self::$snapshot ??= AccessSnapshot::unknown();
        }

        // A failed lookup is not remembered, so the next call asks again instead of trusting "unknown" for the life of the worker.
        try {
            $response = $transport->access();
        } catch (Throwable) {
            self::$resolved = false;
            self::$failedAt = Carbon::now()->getTimestamp();

            return self::$snapshot ?? AccessSnapshot::unknown();
        }

        $data = $response->data();

        if (! is_array($data) || $data === []) {
            self::$resolved = false;
            self::$failedAt = Carbon::now()->getTimestamp();

            return self::$snapshot ?? AccessSnapshot::unknown();
        }

        // The header wins over the body, as everywhere else in this package.
        $version = $response->permissionsVersion();

        if ($version !== null) {
            $data['permissions_version'] = $version;
        }

        $rateLimit = $response->rateLimit();

        if (! $rateLimit->isEmpty()) {
            self::$quota = $rateLimit;
        }

        $ttl = self::ttl();
        $fetchedAt = Carbon::now()->getTimestamp();

        if ($cache !== null && $ttl > 0) {
            try {
                $cache->put($key, $data + ['fetched_at' => $fetchedAt], $ttl);
            } catch (Throwable) {
                // A cache that cannot be written costs another request later, and nothing else.
            }
        }

        self::$rereadUsed = false;
        self::$failedAt = null;
        self::$loadedAt = $fetchedAt;

        return self::$snapshot = AccessSnapshot::fromArray($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(CacheRepository $cache, string $key): ?array
    {
        try {
            $entry = $cache->get($key);
        } catch (Throwable) {
            return null;
        }

        return is_array($entry) && $entry !== [] ? $entry : null;
    }

    private static function forgetCache(): void
    {
        $cache = self::cache();

        if ($cache === null) {
            return;
        }

        try {
            $cache->forget(self::cacheKey());
        } catch (Throwable) {
            // Nothing to do about it: the entry expires on its own.
        }
    }

    private static function cacheKey(): string
    {
        $prefix = self::config('cache.prefix', 'esanj:remote_eloquent:');
        $config = self::resolve('config');
        $scope = $config instanceof ConfigRepository ? RemoteEloquentServiceProvider::cacheScope($config) . ':' : '';

        return (is_string($prefix) ? $prefix : 'esanj:remote_eloquent:') . $scope . 'access';
    }

    // A long-lived worker keeps this class's state between jobs; the snapshot lives no longer than the shared entry.
    private static function expired(): bool
    {
        $ttl = self::ttl();

        return $ttl > 0 && self::$loadedAt !== null && Carbon::now()->getTimestamp() - self::$loadedAt >= $ttl;
    }

    private static function ttl(): int
    {
        $ttl = self::config('cache.access_ttl', 600);

        return is_numeric($ttl) ? (int) $ttl : 0;
    }

    private static function transport(): ?ResourceTransport
    {
        $transport = self::resolve(ResourceTransport::class);

        return $transport instanceof ResourceTransport ? $transport : null;
    }

    private static function cache(): ?CacheRepository
    {
        $factory = self::resolve(CacheFactory::class);

        if (! $factory instanceof CacheFactory) {
            return null;
        }

        $store = self::config('cache.store');

        try {
            return $factory->store(is_string($store) && $store !== '' ? $store : null);
        } catch (Throwable) {
            return null;
        }
    }

    private static function config(string $key, mixed $default = null): mixed
    {
        $config = self::resolve('config');

        if (! $config instanceof ConfigRepository) {
            return $default;
        }

        return $config->get('esanj.remote_eloquent.' . $key, $default);
    }

    private static function resolve(string $abstract): ?object
    {
        try {
            $container = Container::getInstance();

            return $container->bound($abstract) ? $container->make($abstract) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
