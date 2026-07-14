<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Contracts;

use Esanj\RemoteEloquent\DTOs\QueryResult;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\QueryAccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\TransportException;

interface TransportInterface
{
    /**
     * Send one compiled SQL statement (with positional bindings) to the remote
     * Accounting service and return its result.
     *
     * @param  string  $sql  A single, compiled SQL statement using "?" placeholders.
     * @param  array<int, scalar|null>  $bindings  Positional bindings, already prepared by the grammar.
     *
     * @throws InvalidQueryException      The server rejected the statement (HTTP 422 / gRPC INVALID_ARGUMENT).
     * @throws QueryAccessDeniedException The application may not touch that table/operation (403 / PERMISSION_DENIED).
     * @throws TransportException         Connectivity, auth or any other transport-level failure.
     */
    public function runQuery(string $sql, array $bindings): QueryResult;
}