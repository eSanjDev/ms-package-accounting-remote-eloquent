<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Grpc;

use Esanj\RemoteEloquent\Exceptions\TransportException;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;

/**
 * Builds (and memoizes) the gRPC client, verifying the gRPC stack is present
 * and the configured protobuf message classes exist before use.
 */
final class GrpcClientFactory
{
    private ?RemoteEloquentServiceClient $client = null;

    /**
     * @param  array<string, mixed>  $config  config('esanj.remote_eloquent.grpc')
     */
    public function __construct(
        private readonly array $config,
    ) {}

    public function client(): RemoteEloquentServiceClient
    {
        return $this->client ??= $this->build();
    }

    /**
     * @return class-string
     */
    public function requestClass(): string
    {
        $class = (string) ($this->config['request_class'] ?? '');

        if (! class_exists($class)) {
            throw TransportException::grpcUnavailable(
                "The configured gRPC request class [{$class}] does not exist. Generate it from ".
                'eloquent.proto and set esanj.remote_eloquent.grpc.request_class.'
            );
        }

        return $class;
    }

    private function build(): RemoteEloquentServiceClient
    {
        if (! extension_loaded('grpc')) {
            throw TransportException::grpcUnavailable('the ext-grpc PHP extension is not installed.');
        }

        // Check the composer-provided base class BEFORE referencing our stub
        // (which extends it): touching the stub while \Grpc\BaseStub is absent
        // would be a fatal error, not a catchable one.
        if (! class_exists(BaseStub::class) || ! class_exists(ChannelCredentials::class)) {
            throw TransportException::grpcUnavailable(
                'the grpc/grpc composer package is not installed (\\Grpc\\BaseStub missing).'
            );
        }

        $responseClass = (string) ($this->config['response_class'] ?? '');

        if (! class_exists($responseClass)) {
            throw TransportException::grpcUnavailable(
                "The configured gRPC response class [{$responseClass}] does not exist. Generate it from ".
                'eloquent.proto and set esanj.remote_eloquent.grpc.response_class.'
            );
        }

        $credentials = ((bool) ($this->config['secure'] ?? false))
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        return new RemoteEloquentServiceClient(
            (string) ($this->config['host'] ?? '127.0.0.1:50051'),
            ['credentials' => $credentials],
            $responseClass,
        );
    }
}