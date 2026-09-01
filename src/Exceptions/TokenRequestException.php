<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Throwable;

/**
 * Obtaining (or refreshing) the client-credentials access token failed.
 */
final class TokenRequestException extends RemoteEloquentException
{
    public static function missingCredentials(): self
    {
        return new self(
            'Remote model client credentials are not configured. Set REMOTE_ELOQUENT_CLIENT_ID and '.
            'REMOTE_ELOQUENT_CLIENT_SECRET (or the ACCOUNTING_BRIDGE_* equivalents).'
        );
    }

    public static function invalidTokenUrl(string $url): self
    {
        return new self(
            "The OAuth token URL [{$url}] is not a valid URL. Set REMOTE_ELOQUENT_BASE_URL ".
            '(or REMOTE_ELOQUENT_TOKEN_URL / ACCOUNTING_BRIDGE_BASE_URL).',
            context: ['url' => $url],
        );
    }

    public static function malformedResponse(): self
    {
        return new self(
            'The OAuth token endpoint answered successfully but returned no access_token.'
        );
    }

    public static function connectionFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "Could not reach the OAuth token endpoint: {$reason}",
            previous: $previous,
            context: ['reason' => $reason],
        );
    }

    public static function failed(string $error, int $status): self
    {
        return new self(
            "The OAuth token request failed [{$status}]: {$error}",
            code: $status,
            context: ['status' => $status, 'error' => $error],
        );
    }
}