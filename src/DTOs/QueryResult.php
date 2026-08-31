<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\DTOs;

/**
 * The normalized result of a remote query, identical across REST and gRPC.
 */
final class QueryResult
{
    public function __construct(
        public readonly array $rows = [],
        public readonly int $affectedRows = 0,
        public readonly ?string $lastInsertId = null,
    ) {}
}
