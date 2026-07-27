<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Esanj\RemoteEloquent\Exceptions\TransportException;
use Throwable;

/**
 * Decides whether a failed statement may be replayed on the next transport.
 *
 * Only the pipe is ever retried, never the verdict. A statement the server
 * understood and refused — rejected SQL (422 / INVALID_ARGUMENT) or a missing
 * capability feature (403 / PERMISSION_DENIED) — gets the same answer over any
 * transport, and a token failure is shared by both, so none of those fall back.
 *
 * Writes are held to a stricter rule: a statement that may already have reached
 * the server must not be replayed, or an INSERT/UPDATE could apply twice. They
 * fall back only when the failure provably happened before anything was sent
 * (see {@see TransportException::wasDispatched()}), or when "retry_writes" is
 * explicitly enabled.
 */
final class FallbackPolicy
{
    /**
     * Leading keywords that mark a statement as a pure read. Anything else is
     * treated as a write.
     *
     * @var list<string>
     */
    private const READ_STATEMENTS = ['select', 'show', 'describe', 'desc', 'explain'];

    public function __construct(
        private readonly bool $retryWrites = false,
    ) {}

    public function shouldFallBack(Throwable $e, string $sql): bool
    {
        if (! $e instanceof TransportException) {
            return false;
        }

        if (! $e->wasDispatched()) {
            return true;
        }

        return $this->retryWrites || self::isRead($sql);
    }

    /**
     * Whether the statement only reads, judged by its leading keyword.
     */
    public static function isRead(string $sql): bool
    {
        $sql = ltrim($sql, " \t\n\r\0\x0B(");

        return in_array(
            strtolower(substr($sql, 0, strcspn($sql, " \t\n\r("))),
            self::READ_STATEMENTS,
            true,
        );
    }
}