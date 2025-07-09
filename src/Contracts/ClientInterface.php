<?php

namespace Esanj\RemoteEloquent\Contracts;

/**
 * Base interface for all client implementations (REST and gRPC)
 */
interface ClientInterface
{
    /**
     * Send a GET request to the remote service.
     */
    public function get(string $url, array $query = []);

    /**
     * Send a POST request to the remote service.
     */
    public function post(string $url, array $data = []);

    /**
     * Send a PUT request to the remote service.
     */
    public function put(string $url, array $data = []);

    /**
     * Send a DELETE request to the remote service.
     */
    public function delete(string $url): void;
} 