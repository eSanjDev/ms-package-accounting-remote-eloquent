<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

abstract class RemoteEloquentException extends RuntimeException
{
    public function __construct(
        string                  $message,
        public readonly string  $errorCode = '',
        public readonly int     $status = 0,
        public readonly ?string $requestId = null,
        public readonly array   $context = [],
        ?Throwable              $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function render(Request $request): ?Response
    {
        if (!$this->renderingEnabled() || !$request->expectsJson()) {
            return null;
        }

        return new JsonResponse(
            $this->errorPayload(),
            $this->responseStatus(),
            $this->responseHeaders()
        );
    }

    abstract protected function responseStatus(): int;

    protected function userMessage(): string
    {
        return $this->getMessage();
    }

    protected function responseHeaders(): array
    {
        return [];
    }

    protected function responseDetails(): array
    {
        return [];
    }

    protected function errorPayload(): array
    {
        $error = [
            'code' => $this->errorCode !== '' ? $this->errorCode : 'remote_eloquent_error',
            'message' => $this->userMessage(),
        ];

        $details = $this->responseDetails();

        if ($this->debugEnabled()) {
            $details['exception'] = static::class;
            $details['context'] = $this->context;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        if ($this->requestId !== null) {
            $error['request_id'] = $this->requestId;
        }

        return ['error' => $error];
    }

    protected function renderingEnabled(): bool
    {
        if (!function_exists('config')) {
            return false;
        }

        return (bool)config('esanj.remote_eloquent.errors.render', true);
    }

    protected function debugEnabled(): bool
    {
        return function_exists('config') && (bool)config('app.debug', false);
    }
}
