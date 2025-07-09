<?php

namespace Esanj\RemoteEloquent\Services;

use Esanj\RemoteEloquent\Contracts\GrpcClientInterface;
use Esanj\RemoteEloquent\Exceptions\GrpcClientException;
use Exception;
use Google\Protobuf\Internal\Message;
use Grpc\ChannelCredentials;

/**
 * gRPC client implementation for RemoteEloquent
 */
class GrpcClient implements GrpcClientInterface
{
    protected string $serverAddress;
    protected string $serviceName;
    protected $channel;
    protected array $stubs = [];

    public function __construct(string $serverAddress = '', string $serviceName = '')
    {
        $this->serverAddress = $serverAddress;
        $this->serviceName = $serviceName;
        $this->initializeChannel();
    }

    /**
     * Initialize gRPC channel
     */
    protected function initializeChannel(): void
    {
        $this->channel = new \Grpc\Channel(
            $this->serverAddress,
            ['credentials' => ChannelCredentials::createInsecure()]
        );
    }

    /**
     * Get or create gRPC stub for the service
     */
    protected function getStub(string $method): object
    {
        if (!isset($this->stubs[$method])) {
            $stubClass = "\\{$this->serviceName}\\{$method}Stub";
            $this->stubs[$method] = new $stubClass($this->serverAddress, [
                'credentials' => ChannelCredentials::createInsecure(),
            ]);
        }

        return $this->stubs[$method];
    }

    /**
     * Convert Eloquent query to gRPC request
     */
    protected function buildGrpcRequest(string $method, array $data = []): Message
    {
        $requestClass = "\\{$this->serviceName}\\{$method}Request";
        $request = new $requestClass();

        foreach ($data as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($request, $setter)) {
                $request->$setter($value);
            }
        }

        return $request;
    }

    /**
     * Convert gRPC response to array
     */
    protected function grpcResponseToArray($response): array
    {
        if ($response instanceof Message) {
            return $this->messageToArray($response);
        }

        return [];
    }

    /**
     * Convert protobuf message to array
     */
    protected function messageToArray(Message $message): array
    {
        $data = [];
        $reflection = new \ReflectionClass($message);
        
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($message);

            if ($value !== null) {
                $data[$property->getName()] = $value;
            }
        }

        return $data;
    }

    public function get(string $url, array $query = []): array
    {
        try {
            $stub = $this->getStub('Get');
            $request = $this->buildGrpcRequest('Get', $query);

            list($response, $status) = $stub->Get($request)->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new GrpcClientException("gRPC call failed: " . $status->details);
            }

            return $this->grpcResponseToArray($response);
        } catch (Exception $e) {
            throw new GrpcClientException("gRPC GET request failed: " . $e->getMessage());
        }
    }

    public function post(string $url, array $data = []): array
    {
        try {
            $stub = $this->getStub('Create');
            $request = $this->buildGrpcRequest('Create', $data);

            list($response, $status) = $stub->Create($request)->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new GrpcClientException("gRPC call failed: " . $status->details);
            }

            return $this->grpcResponseToArray($response);
        } catch (Exception $e) {
            throw new GrpcClientException("gRPC POST request failed: " . $e->getMessage());
        }
    }

    public function put(string $url, array $data = []): array
    {
        try {
            $stub = $this->getStub('Update');
            $request = $this->buildGrpcRequest('Update', $data);

            list($response, $status) = $stub->Update($request)->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new GrpcClientException("gRPC call failed: " . $status->details);
            }

            return $this->grpcResponseToArray($response);
        } catch (Exception $e) {
            throw new GrpcClientException("gRPC PUT request failed: " . $e->getMessage());
        }
    }

    public function delete(string $url): void
    {
        try {
            $stub = $this->getStub('Delete');
            $request = $this->buildGrpcRequest('Delete', ['id' => $this->extractIdFromUrl($url)]);

            list($response, $status) = $stub->Delete($request)->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new GrpcClientException("gRPC call failed: " . $status->details);
            }
        } catch (Exception $e) {
            throw new GrpcClientException("gRPC DELETE request failed: " . $e->getMessage());
        }
    }

    /**
     * Extract ID from URL for delete operations
     */
    protected function extractIdFromUrl(string $url): string
    {
        $parts = explode('/', trim($url, '/'));
        return end($parts);
    }

    public function setServerAddress(string $address): void
    {
        $this->serverAddress = $address;
        $this->initializeChannel();
    }

    public function getServerAddress(): string
    {
        return $this->serverAddress;
    }

    public function setServiceName(string $serviceName): void
    {
        $this->serviceName = $serviceName;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }
} 