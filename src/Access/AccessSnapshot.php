<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Access;

final class AccessSnapshot
{
    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $resources
     * @param  array<string, mixed>  $quota
     */
    private function __construct(
        private readonly string $application,
        private readonly ?string $permissionsVersion,
        private readonly array $permissions,
        private readonly array $resources,
        private readonly array $quota,
        private readonly bool $known,
    ) {
    }

    /**
     * Accepts the response envelope or the bare access object.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? null;

        if (is_array($data) && (isset($data['permissions']) || isset($data['resources']) || isset($data['application']))) {
            $payload = $data;
        }

        return new self(
            self::readApplication($payload['application'] ?? null),
            self::readVersion($payload['permissions_version'] ?? null),
            self::readPermissions($payload['permissions'] ?? null),
            is_array($payload['resources'] ?? null) ? $payload['resources'] : [],
            is_array($payload['quota'] ?? null) ? $payload['quota'] : [],
            true,
        );
    }

    /**
     * Nothing has been read, and nothing may be concluded from it.
     */
    public static function unknown(): self
    {
        return new self('', null, [], [], [], false);
    }

    /**
     * A snapshot for a test: these permissions, and nothing else.
     *
     * @param  array<array-key, mixed>  $permissions
     */
    public static function fake(array $permissions): self
    {
        return new self('fake', 'fake', self::readPermissions($permissions), [], [], true);
    }

    public function application(): string
    {
        return $this->application;
    }

    /**
     * The value X-Permissions-Version carries; a response quoting another one means this copy has been superseded.
     */
    public function permissionsVersion(): ?string
    {
        return $this->permissionsVersion;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function can(string $permission): bool
    {
        $permission = trim($permission);

        if ($permission === '') {
            return false;
        }

        foreach ($this->permissions as $granted) {
            if ($granted === '*' || $granted === $permission) {
                return true;
            }

            if (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public function cannot(string $permission): bool
    {
        return ! $this->can($permission);
    }

    /**
     * @return array<string, mixed>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * The operations published for one resource.
     *
     * @return list<string>
     */
    public function operations(string $resource): array
    {
        $entry = $this->resources[$resource] ?? null;

        if (! is_array($entry)) {
            return [];
        }

        $operations = $entry['operations'] ?? $entry;

        return is_array($operations) ? self::readPermissions($operations) : [];
    }

    /**
     * Whether one operation on one resource is published.
     */
    public function allows(string $resource, string $operation): bool
    {
        if (array_key_exists($resource, $this->resources)) {
            return in_array($operation, $this->operations($resource), true);
        }

        return $this->can($resource . '.' . $operation);
    }

    /**
     * The budget the access payload published — not the live headers.
     *
     * @return array<string, mixed>
     */
    public function quota(): array
    {
        return $this->quota;
    }

    /**
     * False when nothing was ever read.
     */
    public function isKnown(): bool
    {
        return $this->known;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'application' => $this->application,
            'permissions_version' => $this->permissionsVersion,
            'permissions' => $this->permissions,
            'resources' => $this->resources,
            'quota' => $this->quota,
        ];
    }

    private static function readApplication(mixed $application): string
    {
        if (is_scalar($application)) {
            return (string) $application;
        }

        if (is_array($application)) {
            $name = $application['name'] ?? $application['id'] ?? '';

            return is_scalar($name) ? (string) $name : '';
        }

        return '';
    }

    private static function readVersion(mixed $version): ?string
    {
        return is_scalar($version) && (string) $version !== '' ? (string) $version : null;
    }

    /**
     * @return list<string>
     */
    private static function readPermissions(mixed $permissions): array
    {
        if (! is_array($permissions)) {
            return [];
        }

        $clean = [];

        foreach ($permissions as $permission) {
            if (! is_scalar($permission)) {
                continue;
            }

            $permission = trim((string) $permission);

            if ($permission !== '') {
                $clean[$permission] = true;
            }
        }

        return array_keys($clean);
    }
}
