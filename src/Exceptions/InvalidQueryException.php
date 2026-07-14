<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

/**
 * The server rejected the statement itself (HTTP 422 / gRPC INVALID_ARGUMENT).
 *
 * The Accounting service only accepts a single, single-table statement with no
 * JOINs, UNIONs, stacked statements or comments — see the server-side
 * SqlStatementAnalyzer. Building such a query through Eloquent (a join, a
 * whereHas subquery across tables, ...) surfaces here.
 */
final class InvalidQueryException extends RemoteEloquentException
{
    public static function rejected(string $message): self
    {
        return new self($message !== '' ? $message : 'The remote query was rejected as invalid.');
    }
}