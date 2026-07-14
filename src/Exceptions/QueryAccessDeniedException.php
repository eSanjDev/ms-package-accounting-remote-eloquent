<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

/**
 * The calling application is not allowed to run this table/operation
 * (HTTP 403 / gRPC PERMISSION_DENIED).
 *
 * Access is granted per "<table>.<operation>" by the application's capability
 * features on the Accounting side (see config/query.php there). Owning
 * USER_LIST, for example, permits SELECT on "users" and nothing else.
 */
final class QueryAccessDeniedException extends RemoteEloquentException
{
    public static function denied(string $message): self
    {
        return new self($message !== '' ? $message : 'The remote query was denied by the Accounting service.');
    }
}