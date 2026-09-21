<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Throwable;

/**
 * 401 unauthenticated, after the one permitted refresh.
 */
final class RemoteAuthenticationException extends RemoteEloquentException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function tokenRejected(
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            'The account service rejected this application\'s access token, and rejected it again after a refresh. Check esanj.remote_eloquent.auth.client_id / client_secret and that the client is still active.',
            'unauthenticated',
            401,
            $requestId,
            $context,
        );
    }

    /**
     * Nothing to authenticate with: a configuration gap, caught before any call is attempted.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function missingCredentials(string $setting, array $context = []): self
    {
        return new self(
            sprintf(
                'Remote Eloquent has no credentials: esanj.remote_eloquent.%s is empty. It falls back to the ACCOUNTING_BRIDGE_* variables, so a service already wired to Accounting usually needs nothing new.',
                $setting
            ),
            'unauthenticated',
            0,
            null,
            $context + ['setting' => $setting],
        );
    }

    /**
     * The token endpoint answered, but not with a usable token.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function tokenRequestFailed(
        string $reason,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('Could not obtain an access token for the account service: %s', $reason),
            'unauthenticated',
            0,
            null,
            $context,
            $previous,
        );
    }

    /**
     * The end user's short-lived token could not be issued, so the write was not sent.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function actorExchangeFailed(
        string $reason,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('Could not issue an actor token for the signed-in user, so the write was not sent: %s', $reason),
            'unauthenticated',
            0,
            null,
            $context,
            $previous,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        string $message,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            $message !== '' ? $message : 'The account service rejected this application\'s access token.',
            'unauthenticated',
            401,
            $requestId,
            $context,
        );
    }

    protected function responseStatus(): int
    {
        return 500;
    }

    protected function userMessage(): string
    {
        return 'This service could not authenticate itself with the account service.';
    }
}
