<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Schema;

/**
 * One field as the resource schema describes it.
 */
final class FieldDefinition
{
    /**
     * @param  list<string>|null  $filters     null = the schema did not constrain the operators
     * @param  list<string>|null  $writes      null = the schema did not constrain the operations
     * @param  list<string>|null  $aggregates  null = the schema did not constrain the functions
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly bool $nullable,
        private readonly ?array $filters,
        private readonly bool $sortable,
        private readonly ?array $writes,
        private readonly ?array $aggregates,
        private readonly bool $deprecated,
        private readonly ?string $deprecation,
        private readonly array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(string $name, array $definition): self
    {
        $deprecation = self::deprecationNotice($definition);

        return new self(
            $name,
            isset($definition['type']) && is_string($definition['type']) ? $definition['type'] : 'mixed',
            (bool) ($definition['nullable'] ?? false),
            self::capability($definition, ['filter', 'filters', 'operators', 'ops']),
            self::flag($definition, ['sort', 'sortable']),
            self::capability($definition, ['write', 'writes', 'writable']),
            self::capability($definition, ['aggregate', 'aggregates']),
            (bool) ($definition['deprecated'] ?? false) || $deprecation !== null,
            $deprecation,
            $definition,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Whether this field may be filtered with the given QuerySpec operator.
     */
    public function allowsOperator(string $operator): bool
    {
        if ($this->filters === null) {
            return true;
        }

        return in_array(strtolower(trim($operator)), $this->filters, true);
    }

    /**
     * Whether the schema listed operators at all.
     */
    public function constrainsOperators(): bool
    {
        return $this->filters !== null;
    }

    /**
     * @return list<string>
     */
    public function operators(): array
    {
        return $this->filters ?? [];
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function allowsAggregate(string $function): bool
    {
        if ($this->aggregates === null) {
            return true;
        }

        return in_array(strtolower(trim($function)), $this->aggregates, true);
    }

    /**
     * @return list<string>
     */
    public function aggregates(): array
    {
        return $this->aggregates ?? [];
    }

    /**
     * $operation is "create" or "update".
     */
    public function isWritableOn(string $operation): bool
    {
        if ($this->writes === null) {
            return true;
        }

        return in_array(strtolower(trim($operation)), $this->writes, true);
    }

    /**
     * @return list<string>
     */
    public function writableOn(): array
    {
        return $this->writes ?? [];
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function deprecation(): ?string
    {
        return $this->deprecation;
    }

    /**
     * The untouched definition, for anything this class does not model yet.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $keys
     * @return list<string>|null
     */
    private static function capability(array $definition, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $definition)) {
                continue;
            }

            $value = $definition[$key];

            if ($value === true) {
                return null;
            }

            if ($value === false) {
                return [];
            }

            if (is_array($value)) {
                return self::stringList($value);
            }

            if (is_string($value)) {
                return self::stringList([$value]);
            }
        }

        return null;
    }

    /**
     * A flag the schema may omit.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $keys
     */
    private static function flag(array $definition, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $definition)) {
                return (bool) $definition[$key];
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function deprecationNotice(array $definition): ?string
    {
        $notice = $definition['deprecation'] ?? ($definition['deprecated'] ?? null);

        if (is_string($notice) && trim($notice) !== '') {
            return trim($notice);
        }

        if (is_array($notice)) {
            $parts = [];

            foreach (['message', 'reason', 'replacement', 'sunset', 'since'] as $key) {
                if (isset($notice[$key]) && is_scalar($notice[$key])) {
                    $parts[] = $key === 'message' || $key === 'reason'
                        ? (string) $notice[$key]
                        : $key.': '.$notice[$key];
                }
            }

            return $parts === [] ? null : implode(' - ', $parts);
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $list = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = strtolower(trim($value));
            }
        }

        return array_values(array_unique($list));
    }
}