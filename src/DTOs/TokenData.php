<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\DTOs;

use DateTimeImmutable;
use Exception;

/**
 * An OAuth 2.0 token together with its absolute expiry, safe to cache.
 */
final class TokenData
{
    public DateTimeImmutable $expiresAt;

    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType = 'Bearer',
        public readonly int $expiresIn = 3600,
        public readonly ?string $refreshToken = null,
        public readonly ?string $scope = null,
        ?DateTimeImmutable $expiresAt = null,
    ) {
        $this->expiresAt = $expiresAt ?? (new DateTimeImmutable())->modify("+{$expiresIn} seconds");
    }

    /**
     * @param  array<string, mixed>  $data  A raw OAuth token endpoint response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            tokenType: (string) ($data['token_type'] ?? 'Bearer'),
            expiresIn: (int) ($data['expires_in'] ?? 3600),
            refreshToken: isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            scope: isset($data['scope']) ? (string) $data['scope'] : null,
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function isExpiring(int $bufferSeconds = 0): bool
    {
        if ($bufferSeconds <= 0) {
            return $this->isExpired();
        }

        return $this->expiresAt <= (new DateTimeImmutable())->modify("+{$bufferSeconds} seconds");
    }

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    public function getAuthorizationHeader(): string
    {
        return "{$this->tokenType} {$this->accessToken}";
    }

    /**
     * Seconds remaining before expiry (never negative).
     */
    public function secondsUntilExpiry(): int
    {
        try {
            $now = new DateTimeImmutable();
        } catch (Exception) {
            return 0;
        }

        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }
}