<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Query;

use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;

/**
 * Laravel's internal $query->orders, turned into the QuerySpec "order" list.
 */
final class OrderTranslator
{
    /**
     * What the drivers compile inRandomOrder() into.
     */
    private const RANDOM_FUNCTIONS = ['random(', 'rand(', 'newid('];

    public function __construct(
        private readonly string $resource,
        private readonly string $table,
    ) {
    }

    /**
     * @param  array<int, mixed>|null  $orders  a Builder's $orders, in order
     * @return list<array{field: string, direction: string}>
     */
    public function order(?array $orders): array
    {
        $translated = [];

        // Sequence matters: order by status then created_at is not the same sort as order by created_at then status.
        foreach ($orders ?? [] as $order) {
            $translated[] = $this->clause($order);
        }

        return $translated;
    }

    /**
     * @return array{field: string, direction: string}
     */
    private function clause(mixed $order): array
    {
        if (! is_array($order)) {
            throw UnsupportedQueryException::method(
                'An unreadable order clause',
                sprintf(
                    'Something wrote directly into the query\'s $orders. Sort with orderBy(), latest() or oldest() on the fields the "%s" schema publishes.',
                    $this->resource,
                ),
                $this->context(),
            );
        }

        if (isset($order['type'])) {
            $this->refuseRaw($order);
        }

        $direction = strtolower(trim((string) ($order['direction'] ?? 'asc')));

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw UnsupportedQueryException::method(
                sprintf("orderBy(..., '%s')", $direction),
                'A remote sort runs ascending or descending, and nothing else.',
                ['direction' => $direction] + $this->context(),
            );
        }

        return [
            'field' => $this->field($order['column'] ?? null),
            'direction' => $direction,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function refuseRaw(array $order): never
    {
        $sql = strtolower(is_string($order['sql'] ?? null) ? $order['sql'] : '');

        foreach (self::RANDOM_FUNCTIONS as $function) {
            if (str_contains($sql, $function)) {
                throw UnsupportedQueryException::randomOrder($this->context());
            }
        }

        throw UnsupportedQueryException::rawExpression('orderByRaw', $this->context());
    }

    /**
     * A sortable field name, stripped of this resource's own table qualifier.
     */
    private function field(mixed $column): string
    {
        if ($column instanceof ExpressionContract) {
            throw UnsupportedQueryException::rawExpression('orderBy', $this->context());
        }

        if (! is_string($column)) {
            throw UnsupportedQueryException::method(
                sprintf('orderBy() with a %s column', get_debug_type($column)),
                sprintf(
                    'A remote sort names one field the "%s" schema publishes. A closure or a builder is a sub-select, and one call carries one QuerySpec.',
                    $this->resource,
                ),
                $this->context(),
            );
        }

        $name = trim($column);

        if ($name === '') {
            throw UnsupportedQueryException::method(
                'orderBy() with no column',
                sprintf('A remote sort needs the name of a field the "%s" schema publishes.', $this->resource),
                $this->context(),
            );
        }

        if (str_contains($name, '->')) {
            throw UnsupportedQueryException::jsonWhere(
                'orderBy',
                ['field' => $name] + $this->context(),
            );
        }

        if (! str_contains($name, '.')) {
            return $name;
        }

        $segments = explode('.', $name);
        $unqualified = (string) array_pop($segments);

        if (! $this->qualifierMatches(implode('.', $segments))) {
            throw UnsupportedQueryException::method(
                sprintf("orderBy('%s')", $name),
                sprintf(
                    'The "%s" resource sorts by its own fields only, and the account service cannot see [%s]. Sort on a field it publishes, or order the results here once they are loaded.',
                    $this->resource,
                    $name,
                ),
                ['field' => $name] + $this->context(),
            );
        }

        return $unqualified;
    }

    /**
     * Is this qualifier the resource's own table?
     */
    private function qualifierMatches(string $qualifier): bool
    {
        $qualifier = strtolower(trim($qualifier, " \t\n\r\0\x0B`\"[]"));

        if ($qualifier === '') {
            return false;
        }

        foreach ([$this->table, $this->resource] as $candidate) {
            $candidate = strtolower(trim($candidate));

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $candidate) ?: [$candidate];

            foreach ($parts as $part) {
                $part = trim($part);

                if ($part === $qualifier) {
                    return true;
                }

                $tail = strrchr($part, '.');

                if ($tail !== false && substr($tail, 1) === $qualifier) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return array<string, scalar|null>
     */
    private function context(array $extra = []): array
    {
        return $extra + ['resource' => $this->resource];
    }
}
