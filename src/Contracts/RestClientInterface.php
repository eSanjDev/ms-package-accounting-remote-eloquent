<?php

namespace Esanj\RemoteEloquent\Contracts;

/**
 * Interface for REST API client implementations
 */
interface RestClientInterface extends ClientInterface
{
    /**
     * Set base URL for REST API
     */
    public function setBaseUrl(string $baseUrl): void;

    /**
     * Get base URL for REST API
     */
    public function getBaseUrl(): string;
} 