<?php

namespace Esanj\RemoteEloquent\Contracts;

/**
 * Interface for gRPC client implementations
 */
interface GrpcClientInterface extends ClientInterface
{
    /**
     * Set gRPC server address
     */
    public function setServerAddress(string $address): void;

    /**
     * Get gRPC server address
     */
    public function getServerAddress(): string;

    /**
     * Set gRPC service name
     */
    public function setServiceName(string $serviceName): void;

    /**
     * Get gRPC service name
     */
    public function getServiceName(): string;
} 