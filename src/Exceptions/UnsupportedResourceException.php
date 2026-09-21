<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

/**
 * 404 unknown_resource — the account service exposes no such resource.
 */
final class UnsupportedResourceException extends RemoteEloquentException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function unknown(
        string $resource,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The account service publishes no resource named "%s". Check the spelling against GET /api/remote/v1/access, which lists every resource this application may reach.',
                $resource
            ),
            'unknown_resource',
            404,
            $requestId,
            $context + ['resource' => $resource],
        );
    }

    /**
     * The model never said which resource it maps to.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notConfigured(string $model, array $context = []): self
    {
        return new self(
            sprintf(
                '[%s] does not say which remote resource it maps to. Set protected string $resource = \'users\'; on the model — there is no table name to fall back on, and guessing one would send the query somewhere silently wrong.',
                $model
            ),
            'unknown_resource',
            0,
            null,
            $context + ['model' => $model],
        );
    }

    /**
     * The resource exists, the action on it does not.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unknownAction(
        string $resource,
        string $action,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The "%s" resource has no action [%s]. Its schema lists the actions it does have, under "actions".',
                $resource,
                $action
            ),
            'unknown_resource',
            404,
            $requestId,
            $context + ['resource' => $resource, 'action' => $action],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        string $message,
        string $resource,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            $message !== '' ? $message : sprintf('The account service publishes no resource named "%s".', $resource),
            'unknown_resource',
            404,
            $requestId,
            $context + ['resource' => $resource],
        );
    }

    protected function responseStatus(): int
    {
        return 500;
    }
}
