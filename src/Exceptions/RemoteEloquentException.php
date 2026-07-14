<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception thrown by the remote-eloquent package.
 *
 * It intentionally does NOT extend Illuminate's QueryException, so the
 * connection's retry/reconnect logic never mistakes a transport failure for a
 * dropped PDO connection.
 */
class RemoteEloquentException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}