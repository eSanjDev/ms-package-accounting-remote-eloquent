<?php

namespace Esanj\RemoteEloquent;

use Esanj\RemoteEloquent\Builder\ApiQueryBuilder;
use Esanj\RemoteEloquent\Contracts\ClientInterface;
use Esanj\RemoteEloquent\Contracts\GrpcClientInterface;
use Esanj\RemoteEloquent\Contracts\RestClientInterface;
use Illuminate\Database\Eloquent\Builder;
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
     * Create a new record in the remote service.
     *
     * @param Builder $query
     * @return bool
     */
    protected function performInsert(Builder $query)
    {
        // Convert Eloquent model attributes into an array
        $data = $this->attributesToArray();

        // Call the remote API to create a new record
        $response = $this->getApiClient()->post($this->getRemoteEndpoint(), $data);

        if (!is_array($response)) {
            // If the remote service did not return a valid array, handle the error
            return false;
        }

        // Assume the remote service returns some identifier e.g. 'id'
        if (isset($response[$this->getKeyName()])) {
            $this->setAttribute($this->getKeyName(), $response[$this->getKeyName()]);
        }

        // Mark the model as existing in Eloquent
        $this->exists = true;

        // Sync original attributes so that Eloquent considers the model clean
        $this->syncOriginal();

        return true;
    }

    /**
     * Update an existing record in the remote service.
     *
     * @param Builder $query
     * @return bool
     */
    protected function performUpdate(Builder $query)
    {
        if (!$this->exists) {
            // If model does not exist remotely, skip
            return false;
        }

        // Only send updated (dirty) attributes
        $data = $this->getDirty();
        if (empty($data)) {
            // If nothing is changed, simply return true
            return true;
        }

        // Identify the remote record by the primary key
        $id = $this->getKey();

        // Make remote PUT request to update the record
        $this->getApiClient()->put($this->getRemoteEndpoint($id), $data);

        // We assume if no exception was thrown, it's successful
        $this->syncOriginal();

        return true;
    }

    /**
     * Delete an existing record in the remote service.
     */
    protected function performDeleteOnModel()
    {
        if (!$this->exists) {
            // If the model doesn't exist on remote
            return;
        }

        // Identify the remote record by the primary key
        $id = $this->getKey();

        // Send DELETE request
        $this->getApiClient()->delete($this->getRemoteEndpoint($id));

        // Mark the model as non-existent
        $this->exists = false;
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
            return app(GrpcClientInterface::class);
        }

        return app(RestClientInterface::class);
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
    public function setClientType(string $type): void
    {
        if (!in_array($type, ['rest', 'grpc'])) {
            throw new InvalidArgumentException('Client type must be either "rest" or "grpc"');
        }

        $this->clientType = $type;
    }

    /**
     * Builds the endpoint path for the remote resource.
     *
     * @param mixed $id
     * @return string
     *
     * For example, if the remote resource is "products",
     * then "/products" for listing and "/products/{id}" for a single record.
     */
    protected function getRemoteEndpoint($id = null): string
    {
        $resourcePath = $this->getTable();  // e.g. "products" if your model table is "products"
        return $id ? "{$resourcePath}/{$id}" : $resourcePath;
    }
}
