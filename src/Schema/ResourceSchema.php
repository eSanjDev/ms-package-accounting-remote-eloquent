<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Schema;

/**
 * A read-only view over GET {resource}/schema.
 */
final class ResourceSchema
{
    /**
     * @param  array<string, FieldDefinition>  $fields
     * @param  list<string>  $defaultFields
     * @param  list<string>  $includes
     * @param  array<string, array<string, mixed>>  $relations
     * @param  list<string>  $actions
     * @param  array<string, int>  $limits
     */
    private function __construct(
        private readonly string $name,
        private readonly int $version,
        private readonly string $versionTag,
        private readonly string $key,
        private readonly string $keyType,
        private readonly bool $softDeletes,
        private readonly array $defaultFields,
        private readonly array $fields,
        private readonly array $includes,
        private readonly array $relations,
        private readonly array $actions,
        private readonly array $limits,
    ) {
    }

    /**
     * Accepts the endpoint's envelope or the bare schema object.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['resource']) && isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $name = isset($payload['resource']) && is_string($payload['resource']) ? $payload['resource'] : '';
        $key = $payload['key'] ?? [];

        $fields = [];

        foreach (self::arrayValue($payload['fields'] ?? null) as $field => $definition) {
            if (is_array($definition)) {
                $fields[(string) $field] = FieldDefinition::fromArray((string) $field, $definition);
            }
        }

        $relations = [];

        foreach (self::arrayValue($payload['relations'] ?? null) as $relation => $definition) {
            $relations[(string) $relation] = is_array($definition) ? $definition : [];
        }

        $limits = [];

        foreach (self::arrayValue($payload['limits'] ?? null) as $limit => $value) {
            if (is_numeric($value)) {
                $limits[(string) $limit] = (int) $value;
            }
        }

        [$version, $versionTag] = self::readVersion($payload['version'] ?? null, $name);

        return new self(
            $name,
            $version,
            $versionTag,
            is_array($key)
                ? (isset($key['name']) && is_string($key['name']) ? $key['name'] : 'id')
                : (is_string($key) && $key !== '' ? $key : 'id'),
            is_array($key) && isset($key['type']) && is_string($key['type']) ? $key['type'] : 'int',
            (bool) ($payload['soft_deletes'] ?? false),
            self::stringList($payload['default_fields'] ?? null),
            $fields,
            self::stringList($payload['includes'] ?? null),
            $relations,
            self::stringList(array_map(
                static fn (mixed $action): mixed => is_array($action) ? ($action['name'] ?? null) : $action,
                is_array($payload['actions'] ?? null) ? $payload['actions'] : [],
            )),
            $limits,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The numeric part of "users@3"; 0 when the server did not version it.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * The value X-Schema-Version carries, for example "users@3".
     */
    public function versionTag(): string
    {
        return $this->versionTag;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function keyType(): string
    {
        return $this->keyType;
    }

    public function softDeletes(): bool
    {
        return $this->softDeletes;
    }

    /**
     * The projection the server applies when "fields" is left empty.
     *
     * @return list<string>
     */
    public function defaultFields(): array
    {
        return $this->defaultFields;
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /**
     * @return array<string, FieldDefinition>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * @return list<string>
     */
    public function includes(): array
    {
        return $this->includes;
    }

    /**
     * Whether the payload listed includes at all.
     */
    public function publishesIncludes(): bool
    {
        return $this->includes !== [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function relations(): array
    {
        return $this->relations;
    }

    public function publishesRelations(): bool
    {
        return $this->relations !== [];
    }

    /**
     * Whether a relation may carry a "has" condition.
     */
    public function supportsHas(string $relation): bool
    {
        if (! isset($this->relations[$relation])) {
            return false;
        }

        return (bool) ($this->relations[$relation]['has'] ?? true);
    }

    public function supportsCount(string $relation): bool
    {
        if (! isset($this->relations[$relation])) {
            return false;
        }

        return (bool) ($this->relations[$relation]['count'] ?? true);
    }

    /**
     * The resource a relation points at, so a nested condition can be checked against the right schema.
     */
    public function relationResource(string $relation): ?string
    {
        $resource = $this->relations[$relation]['resource'] ?? null;

        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function hasAction(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /**
     * A published limit, or $default when the server does not state one.
     */
    public function limit(string $name, int $default): int
    {
        return $this->limits[$name] ?? $default;
    }

    /**
     * @return array<string, int>
     */
    public function limits(): array
    {
        return $this->limits;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function readVersion(mixed $version, string $resource): array
    {
        if (is_int($version)) {
            return [$version, $resource === '' ? (string) $version : $resource.'@'.$version];
        }

        if (! is_string($version) || trim($version) === '') {
            return [0, ''];
        }

        $version = trim($version);
        $position = strrpos($version, '@');

        if ($position === false) {
            return [
                is_numeric($version) ? (int) $version : 0,
                is_numeric($version) && $resource !== '' ? $resource.'@'.$version : $version,
            ];
        }

        $numeric = substr($version, $position + 1);

        return [is_numeric($numeric) ? (int) $numeric : 0, $version];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $list = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return array_values(array_unique($list));
    }
}