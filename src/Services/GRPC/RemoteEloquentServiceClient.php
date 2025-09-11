<?php

namespace Esanj\RemoteEloquent\Services\GRPC;

use Grpc\BaseStub;
use Grpc\UnaryCall;

/**
 * Client stub for RemoteEloquentService.
 */
class RemoteEloquentServiceClient extends BaseStub
{
    /**
     * @param string $hostname
     * @param array $opts
     * @param \Grpc\Channel|null $channel
     */
    public function __construct(string $hostname, array $opts = [], ?\Grpc\Channel $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * RunQuery 메서드
     *
     * @param QueryRequest $argument
     * @param array $metadata
     * @param array $options
     *
     * @return UnaryCall
     */
    public function RunQuery(QueryRequest $argument, $metadata = [], $options = []): \Grpc\UnaryCall
    {
        return $this->_simpleRequest(
            '/eloquent.query.RemoteEloquentService/RunQuery',
            $argument,
            ['Esanj\RemoteEloquent\Services\GRPC\QueryResponse', 'decode'],
            $metadata,
            $options
        );
    }
}
