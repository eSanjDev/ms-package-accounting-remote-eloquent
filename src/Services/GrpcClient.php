<?php

namespace Esanj\RemoteEloquent\Services;

use Esanj\RemoteEloquent\Contracts\GrpcClientInterface;
use Esanj\RemoteEloquent\Exceptions\GrpcClientException;
use Esanj\RemoteEloquent\Services\GRPC\QueryRequest;
use Esanj\RemoteEloquent\Services\GRPC\QueryResponse;
use Esanj\RemoteEloquent\Services\GRPC\RemoteEloquentServiceClient;
use Exception;
use Grpc\ChannelCredentials;
use const Grpc\STATUS_OK;

/**
 * gRPC client implementation for RemoteEloquent
 */
class GrpcClient implements GrpcClientInterface
{
    protected $channel;

    public function __construct(protected string $serverAddress)
    {
        $this->initializeChannel();
    }

    /**
     * Initialize gRPC channel
     */
    protected function initializeChannel(): void
    {
        $this->channel = new RemoteEloquentServiceClient($this->getServerAddress(), [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    /**
     * Convert Eloquent query to gRPC request
     */
    protected function buildGrpcRequest(string $sql = null): QueryRequest
    {
        $request = new QueryRequest();
        $request->setSql($sql);

        return $request;
    }

    /**
     * Convert gRPC response to array
     */
    protected function grpcResponseToArray($response): array
    {
        if ($response instanceof QueryResponse) {
            return $this->messageToArray($response);
        }

        return [];
    }

    /**
     * Convert protobuf message to array
     */
    protected function messageToArray(QueryResponse $response): array
    {
        $data = [];

        foreach ($response->getRows() as $item) {

            $value = $item->getFields();

            if ($value !== null) {

                $data[] = collect($value)->toArray();
            }
        }

        return $data;
    }

    public function run(string $query = null): array
    {
        try {
            $request = $this->buildGrpcRequest($query);

            list($response, $status) = $this->channel->RunQuery($request)->wait();

            if ($status->code !== STATUS_OK) {
                throw new GrpcClientException("gRPC call failed: " . $status->details);
            }
            return $this->grpcResponseToArray($response);
        } catch (Exception $e) {
            throw new GrpcClientException("gRPC request failed: " . $e->getMessage());
        }
    }

    public function setServerAddress(string $address): void
    {
        $this->serverAddress = $address;
    }

    public function getServerAddress(): string
    {
        return $this->serverAddress;
    }
}
