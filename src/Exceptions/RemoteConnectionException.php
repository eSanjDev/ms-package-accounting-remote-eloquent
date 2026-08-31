<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

final class RemoteConnectionException extends RemoteEloquentException
{
    public static function insertIdUnavailable(): self
    {
        return new self(
            'The insert succeeded but Accounting returned no last_insert_id, so the model has no key. '.
            'Upgrade the Accounting deployment to one that returns it, or give this model a '.
            'client-generated key (HasUuids / HasUlids) — otherwise every later save() and delete() '.
            'on the returned model compiles to "where `id` is null" and matches no rows.'
        );
    }

    public static function transactionsUnsupported(): self
    {
        return new self(
            'The remote connection has no real transaction: statements run one call at a time '.
            'and nothing can be rolled back, so a failure halfway through leaves the earlier '.
            'writes applied. For atomicity, put the whole operation behind a single Accounting '.
            'endpoint that opens a local transaction, or write a compensating action. To run the '.
            'block without any atomicity anyway, set esanj.remote_eloquent.allow_unsafe_transactions.'
        );
    }

    public static function pdoUnavailable(): self
    {
        return new self(
            'The remote connection has no PDO handle: queries run over gRPC/REST. '.
            'This usually means an unsupported operation (schema introspection, raw PDO access, '.
            'or a nested transaction) reached the remote connection.'
        );
    }
}
