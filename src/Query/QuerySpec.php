<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Query;

use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;

/**
 * The body of POST {resource}/query, built up by the Eloquent translation layer.
 */
final class QuerySpec
{
    public const TRASHED_WITH = 'with';

    public const TRASHED_ONLY = 'only';

    private const TRASHED_MODES = [self::TRASHED_WITH, self::TRASHED_ONLY];

    /** @var list<string> */
    private array $fields = [];

    /** @var list<Where> */
    private array $where = [];

    /** @var list<array{field: string, direction: string}> */
    private array $order = [];

    private ?int $limit = null;

    private int $offset = 0;

    private bool $withTotal = false;

    /** @var list<string> */
    private array $include = [];

    /** @var list<string> */
    private array $counts = [];

    private ?string $trashed = null;

    private bool $distinct = false;

    public static function make(): self
    {
        return new self();
    }

    /**
     * Replace the projection.
     */
    public function fields(string|array ...$fields): self
    {
        $this->fields = $this->flatten($fields, 'Fields');

        return $this;
    }

    public function where(Where ...$conditions): self
    {
        foreach ($conditions as $condition) {
            $this->where[] = $condition;
        }

        return $this;
    }

    public function order(string $field, string $direction = 'asc'): self
    {
        $field = trim($field);

        if ($field === '') {
            throw new InvalidQueryException('An order clause needs a field name.', 'invalid_query');
        }

        $normalized = strtolower(trim($direction));

        if ($normalized !== 'asc' && $normalized !== 'desc') {
            throw new InvalidQueryException(
                sprintf(
                    'Order direction is either "asc" or "desc", "%s" given for "%s".',
                    $direction,
                    $field,
                ),
                'invalid_query',
            );
        }

        $this->order[] = ['field' => $field, 'direction' => $normalized];

        return $this;
    }

    /**
     * Drop every order clause collected so far, for reorder().
     */
    public function reorder(): self
    {
        $this->order = [];

        return $this;
    }

    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidQueryException(
                sprintf('A remote limit is at least 1, %d given.', $limit),
                'invalid_query',
            );
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidQueryException(
                sprintf('A remote offset cannot be negative, %d given.', $offset),
                'invalid_query',
            );
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Ask for the matching row count alongside the page.
     */
    public function withTotal(bool $withTotal = true): self
    {
        $this->withTotal = $withTotal;

        return $this;
    }

    public function include(string|array ...$relations): self
    {
        $this->include = $this->merge($this->include, $this->flatten($relations, 'Includes'));

        return $this;
    }

    public function counts(string|array ...$relations): self
    {
        $this->counts = $this->merge($this->counts, $this->flatten($relations, 'Counts'));

        return $this;
    }

    /**
     * null keeps soft-deleted rows out, "with" adds them, "only" returns just them.
     */
    public function trashed(?string $trashed): self
    {
        if ($trashed === null) {
            $this->trashed = null;

            return $this;
        }

        $normalized = strtolower(trim($trashed));

        if (! in_array($normalized, self::TRASHED_MODES, true)) {
            throw new InvalidQueryException(
                sprintf(
                    'Trashed is null, "%s" or "%s", "%s" given.',
                    self::TRASHED_WITH,
                    self::TRASHED_ONLY,
                    $trashed,
                ),
                'invalid_query',
            );
        }

        $this->trashed = $normalized;

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * The request body, and the one place to read a spec back from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [];

        if ($this->fields !== []) {
            $body['fields'] = $this->fields;
        }

        if ($this->where !== []) {
            $body['where'] = array_map(static fn (Where $where): array => $where->toArray(), $this->where);
        }

        if ($this->order !== []) {
            $body['order'] = $this->order;
        }

        if ($this->limit !== null) {
            $body['limit'] = $this->limit;
        }

        if ($this->offset !== 0) {
            $body['offset'] = $this->offset;
        }

        if ($this->withTotal) {
            $body['with_total'] = true;
        }

        if ($this->include !== []) {
            $body['include'] = $this->include;
        }

        if ($this->counts !== []) {
            $body['counts'] = $this->counts;
        }

        if ($this->trashed !== null) {
            $body['trashed'] = $this->trashed;
        }

        if ($this->distinct) {
            $body['distinct'] = true;
        }

        return $body;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function flatten(array $values, string $context): array
    {
        $flat = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($this->flatten($value, $context) as $nested) {
                    $flat[] = $nested;
                }

                continue;
            }

            if (! is_string($value)) {
                throw new InvalidQueryException(
                    sprintf('%s are given as strings, %s given.', $context, get_debug_type($value)),
                    'invalid_query',
                );
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                throw new InvalidQueryException(
                    sprintf('%s cannot contain an empty name.', $context),
                    'invalid_query',
                );
            }

            $flat[] = $trimmed;
        }

        return array_values(array_unique($flat));
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $additional
     * @return list<string>
     */
    private function merge(array $current, array $additional): array
    {
        return array_values(array_unique(array_merge($current, $additional)));
    }
}