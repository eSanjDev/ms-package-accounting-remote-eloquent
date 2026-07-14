<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Grpc;

use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\UnaryCall;

/**
 * Minimal gRPC client stub for eloquent.query.RemoteEloquentService.
 *
 * Kept transport-agnostic of the concrete protobuf classes: the response class
 * used to decode the reply is injected, so the same stub works with whichever
 * generated QueryResponse a consuming service compiles from eloquent.proto.
 *
 * Only referenced when ext-grpc and grpc/grpc are installed (see GrpcTransport),
 * so extending \Grpc\BaseStub never breaks REST-only installs.
 */
final class RemoteEloquentServiceClient extends BaseStub
{
    /** @var class-string<Message> */
    private string $responseClass;

    /**
     * @param  array<string, mixed>  $opts
     * @param  class-string<Message>  $responseClass
     */
    public function __construct(string $hostname, array $opts, string $responseClass, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
        $this->responseClass = $responseClass;
    }

    /**
     * @param  array<string, array<int, string>>  $metadata
     * @param  array<string, mixed>  $options
     */
    public function RunQuery(Message $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/eloquent.query.RemoteEloquentService/RunQuery',
            $argument,
            [$this->responseClass, 'decode'],
            $metadata,
            $options,
        );
    }
}