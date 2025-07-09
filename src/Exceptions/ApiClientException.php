<?php

namespace Esanj\RemoteEloquent\Exceptions;

use Exception;

final class ApiClientException extends Exception
{
    public static function fromErrorResponse(string $message): self
    {
        return new self("API Client Error: {$message}");
    }
}
