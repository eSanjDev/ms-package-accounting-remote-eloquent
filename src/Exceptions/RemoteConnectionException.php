<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

/**
 * Something asked the remote connection for a capability it does not have —
 * most commonly a raw PDO handle or a real database transaction. Neither exists
 * because the connection executes over gRPC/REST, not a local driver.
 */
final class RemoteConnectionException extends RemoteEloquentException
{
    public static function pdoUnavailable(): self
    {
        return new self(
            'The remote connection has no PDO handle: queries run over gRPC/REST. '.
            'This usually means an unsupported operation (schema introspection, raw PDO access, '.
            'or a nested transaction) reached the remote connection.'
        );
    }
}