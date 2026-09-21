<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AccessDeniedException extends RemoteEloquentException implements HttpExceptionInterface
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        string $errorCode = 'permission_denied',
        int $status = 403,
        ?string $requestId = null,
        array $context = [],
        public readonly ?string $requiredPermission = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $requestId, $context, $previous);
    }

    /**
     * The permission the application would need, when the server named one.
     */
    public function requiredPermission(): ?string
    {
        return $this->requiredPermission;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function permissionDenied(
        string $resource,
        string $operation,
        ?string $permission = null,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            $permission !== null
                ? sprintf('This application may not %s "%s": it is missing the [%s] permission.', $operation, $resource, $permission)
                : sprintf('This application may not %s "%s".', $operation, $resource),
            'permission_denied',
            403,
            $requestId,
            $context + ['resource' => $resource, 'operation' => $operation],
            $permission,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function privilegedAccount(
        string $resource,
        string|int|null $id = null,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf('That "%s" record is privileged and the account service manages it itself.', $resource),
            'privileged_account',
            403,
            $requestId,
            $context + ['resource' => $resource, 'id' => $id],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function actorDenied(
        string $resource,
        string $operation,
        ?string $permission = null,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The signed-in user may not %s "%s". If this ran without a signed-in user, the write needs an actor token: see the actor.exchange_url setting.',
                $operation,
                $resource
            ),
            'actor_denied',
            403,
            $requestId,
            $context + ['resource' => $resource, 'operation' => $operation],
            $permission,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        string $code,
        string $message,
        ?string $permission = null,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            $message !== '' ? $message : 'The account service refused this request.',
            $code !== '' ? $code : 'permission_denied',
            403,
            $requestId,
            $context,
            $permission,
        );
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    protected function responseStatus(): int
    {
        return 403;
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseDetails(): array
    {
        return $this->requiredPermission !== null
            ? ['required_permission' => $this->requiredPermission]
            : [];
    }
}
