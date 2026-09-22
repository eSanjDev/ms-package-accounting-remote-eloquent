<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TransportException extends RemoteEloquentException implements HttpExceptionInterface
{
    private const USER_MESSAGE = 'The account service is briefly unavailable. Please try again in a moment.';

    public static function connectionFailed(
        string     $reason,
        array      $context = [],
        ?Throwable $previous = null,
    ): self
    {
        return new self(
            sprintf('Could not reach the account service: %s', $reason),
            'transport_unreachable',
            0,
            null,
            $context,
            $previous,
        );
    }

    public static function tlsFailure(
        string     $reason,
        array      $context = [],
        ?Throwable $previous = null,
    ): self
    {
        return new self(
            sprintf('The TLS handshake with the account service failed: %s. The request was not sent in the clear.', $reason),
            'transport_tls_failure',
            0,
            null,
            $context,
            $previous,
        );
    }

    public static function unavailable(
        ?string $requestId = null,
        array   $context = [],
        string  $message = '',
    ): self
    {
        return new self(
            $message !== '' ? $message : 'The account service reported itself unavailable (503).',
            'unavailable',
            503,
            $requestId,
            $context,
        );
    }

    public static function serverError(
        int     $status,
        string  $code,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf('The account service failed while handling this call (%d%s). Quote the request id when reporting it.', $status, $code !== '' ? ' ' . $code : ''),
            $code !== '' ? $code : 'internal_error',
            $status,
            $requestId,
            $context,
        );
    }

    public static function malformedResponse(
        string     $reason,
        ?string    $requestId = null,
        array      $context = [],
        ?Throwable $previous = null,
    ): self
    {
        return new self(
            sprintf('The account service answered with something this client cannot read: %s', $reason),
            'malformed_response',
            0,
            $requestId,
            $context,
            $previous,
        );
    }

    public static function unexpectedStatus(
        int     $status,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf('The account service answered %d, which this client has no mapping for.', $status),
            'unexpected_status',
            $status,
            $requestId,
            $context,
        );
    }

    public function getStatusCode(): int
    {
        return 503;
    }

    public function getHeaders(): array
    {
        return [];
    }

    protected function responseStatus(): int
    {
        return 503;
    }

    protected function userMessage(): string
    {
        return self::USER_MESSAGE;
    }
}
