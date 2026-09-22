<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Idempotency;

/**
 * Which Idempotency-Key belongs to which business operation.
 */
final class OperationContext
{
    /** @var array<string, string> */
    private array $keys = [];

    /** @var array<string, int> */
    private array $sequence = [];

    private string $scope;

    /**
     * @param  string  $scope  empty = a fresh random scope for this instance
     */
    public function __construct(string $scope = '')
    {
        $this->scope = $scope !== '' ? $scope : self::randomScope();
    }

    /**
     * Bind every key minted from here on to a stable identifier.
     */
    public function useScope(string $scope): void
    {
        if ($scope === '' || $scope === $this->scope) {
            return;
        }

        $this->scope = $scope;
        $this->keys = [];
        $this->sequence = [];
    }

    public function scope(): string
    {
        return $this->scope;
    }

    /**
     * The Idempotency-Key for this operation, stable until forget().
     */
    public function keyFor(string $operation, string $resource, string|int|null $id): string
    {
        $signature = $this->signature($operation, $resource, $id);

        if (isset($this->keys[$signature])) {
            return $this->keys[$signature];
        }

        $attempt = $this->sequence[$signature] ?? 0;
        $this->sequence[$signature] = $attempt + 1;

        return $this->keys[$signature] = $this->derive($signature, $attempt);
    }

    /**
     * End the operation: the next keyFor() with the same signature mints a new key, because it is a new operation.
     */
    public function forget(string $operation, string $resource, string|int|null $id): void
    {
        unset($this->keys[$this->signature($operation, $resource, $id)]);
    }

    public function has(string $operation, string $resource, string|int|null $id): bool
    {
        return isset($this->keys[$this->signature($operation, $resource, $id)]);
    }

    /**
     * Forget every in-flight operation without changing the scope.
     */
    public function flush(): void
    {
        // The sequence stays, so the next operation derives a new key instead of replaying a spent one.
        $this->keys = [];
    }

    private function signature(string $operation, string $resource, string|int|null $id): string
    {
        return $operation . '|' . $resource . '|' . ($id === null ? '' : (string) $id);
    }

    /**
     * A UUID-shaped key derived from the scope, so it is reproducible inside one scope and unguessable outside it.
     */
    private function derive(string $signature, int $attempt): string
    {
        $digest = hash('sha256', $this->scope . '|' . $signature . '|' . $attempt, true);

        return self::formatUuid($digest);
    }

    private static function randomScope(): string
    {
        return self::formatUuid(random_bytes(16));
    }

    /**
     * Lay 16 bytes out as a version-4 UUID.
     */
    private static function formatUuid(string $bytes): string
    {
        $bytes = substr($bytes, 0, 16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
