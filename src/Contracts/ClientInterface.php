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
    public function run(string $query = null);
}
