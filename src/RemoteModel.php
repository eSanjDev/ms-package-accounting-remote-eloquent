<?php

namespace Esanj\RemoteEloquent;

use Esanj\RemoteEloquent\Builder\ApiQueryBuilder;
use Esanj\RemoteEloquent\Contracts\ClientInterface;
use Esanj\RemoteEloquent\Contracts\GrpcClientInterface;
use Esanj\RemoteEloquent\Contracts\RestClientInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

abstract class RemoteModel extends Model
{
    /**
     * Indicates that the primary key is not auto-increment,
     * since remote service might handle IDs differently.
     */
    public $incrementing = false;

    /**
     * The client type to use for this model
     * Can be 'rest' or 'grpc'
     */
    protected string $clientType = 'rest';

    public string $address;

    /**
     * RemoteModel constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * Override Eloquent's default builder to use our custom ApiQueryBuilder.
     */
    public function newEloquentBuilder($query)
    {
        return new ApiQueryBuilder($query);
    }


    /**
     * Get the appropriate API client based on client type
     *
     * @return ClientInterface
     */
    public function getApiClient(): ClientInterface
    {
        $clientType = $this->getClientType();

        if ($clientType === 'grpc') {
            $client = app(GrpcClientInterface::class);
            $client->setServerAddress($this->getAddress());
            return $client;
        }

       // return app(RestClientInterface::class);
    }

    /**
     * Get the client type for this model
     *
     * @return string
     */
    protected function getClientType(): string
    {
        return $this->clientType;
    }

    /**
     * Set the client type for this model
     *
     * @param string $type
     * @return void
     */
    protected function setClientType(string $type): void
    {
        if (!in_array($type, ['rest', 'grpc'])) {
            throw new InvalidArgumentException('Client type must be either "rest" or "grpc"');
        }

        $this->clientType = $type;
    }

    protected function getAddress(): string
    {
        return $this->address;
    }
}
