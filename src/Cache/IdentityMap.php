<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Cache;

/**
 * The records this request has already fetched by id, so the second lookup of the same row costs nothing.
 */
final class IdentityMap
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    private ?string $schemaVersion = null;

    private ?string $permissionsVersion = null;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * @param  bool  $enabled  config("esanj.remote_eloquent.cache.identity_map")
     */
    public function __construct(private readonly bool $enabled = true)
    {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record the versions the server reported on its last answer.
     */
    public function observe(?string $schemaVersion, ?string $permissionsVersion): void
    {
        if ($schemaVersion !== null && $schemaVersion !== '') {
            $this->schemaVersion = $schemaVersion;
        }

        if ($permissionsVersion !== null && $permissionsVersion !== '') {
            $this->permissionsVersion = $permissionsVersion;
        }
    }

    public function schemaVersion(): ?string
    {
        return $this->schemaVersion;
    }

    public function permissionsVersion(): ?string
    {
        return $this->permissionsVersion;
    }

    /**
     * The key for one record under one exact request shape.
     *
     * @param  array<int, string>  $fields   [] = the resource's default projection
     * @param  array<int, string>  $include
     */
    public function key(
        string $application,
        ?string $actor,
        string $resource,
        string|int $id,
        array $fields = [],
        array $include = [],
        ?string $trashed = null,
        ?string $schemaVersion = null,
        ?string $permissionsVersion = null,
    ): string {
        $fields = $this->canonical($fields);
        $include = $this->canonical($include);

        $signature = implode('|', [
            'app=' . $application,
            'actor=' . ($actor ?? ''),
            'fields=' . ($fields === [] ? '*' : implode(',', $fields)),
            'include=' . implode(',', $include),
            'trashed=' . ($trashed ?? ''),
            'schema=' . ($schemaVersion ?? $this->schemaVersion ?? ''),
            'perms=' . ($permissionsVersion ?? $this->permissionsVersion ?? ''),
        ]);

        return $this->prefixFor($resource, $id) . hash('xxh128', $signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $record = $this->records[$key] ?? null;

        if ($record === null) {
            $this->misses++;

            return null;
        }

        $this->hits++;

        return $record;
    }

    public function has(string $key): bool
    {
        return $this->enabled && isset($this->records[$key]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function put(string $key, array $record): void
    {
        if ($this->enabled) {
            $this->records[$key] = $record;
        }
    }

    public function forgetKey(string $key): void
    {
        unset($this->records[$key]);
    }

    /**
     * Drop every stored projection of one record.
     */
    public function forget(string $resource, string|int $id): void
    {
        $prefix = $this->prefixFor($resource, $id);

        foreach (array_keys($this->records) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->records[$key]);
            }
        }
    }

    /**
     * Drop everything held for one resource, whatever the id.
     */
    public function forgetResource(string $resource): void
    {
        $prefix = strtolower(trim($resource)) . '|';

        foreach (array_keys($this->records) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->records[$key]);
            }
        }
    }

    /**
     * Start a new request's map.
     */
    public function flush(): void
    {
        $this->records = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    private function prefixFor(string $resource, string|int $id): string
    {
        return strtolower(trim($resource)) . '|' . $id . '|';
    }

    /**
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private function canonical(array $names): array
    {
        $clean = [];

        foreach ($names as $name) {
            $trimmed = trim($name);

            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        $clean = array_values(array_unique($clean));

        sort($clean);

        return $clean;
    }
}
