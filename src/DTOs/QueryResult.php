<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\DTOs;

/**
 * The normalized result of a remote query, identical across REST and gRPC.
 */
final class QueryResult
{
    /**
     * @param  list<array<string, string>>  $rows  Result rows for SELECT (empty for writes). Every value is a string.
     * @param  int  $affectedRows  Affected rows for INSERT/UPDATE/DELETE (0 for SELECT).
     * @param  string|null  $lastInsertId  Auto-increment id for INSERT, when the server provides one.
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $affectedRows = 0,
        public readonly ?string $lastInsertId = null,
    ) {}
}