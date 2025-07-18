<?php

namespace Esanj\RemoteEloquent\Builder;

use Esanj\RemoteEloquent\Contracts\ClientInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiQueryBuilder extends Builder
{
    /**
     * We'll keep track of all where clauses in a local array
     */
    protected array $remoteWheres = [];

    /**
     * Holds the conditions for remote queries.
     */
    protected array $remoteConditions = [];

    public function insertRemote(array $attributes)
    {
        return $this->model->getApiClient()->post($this->getEndpoint(), $attributes);
    }

    public function getApiClient(): ClientInterface
    {
        return $this->model->getApiClient();
    }

    protected function getEndpoint(): string
    {
        return '/' . str($this->model->getTable())->plural()->snake();
    }

    public function updateRemote($id, array $attributes)
    {
        return $this->model->getApiClient()->put($this->getEndpoint() . '/' . $id, $attributes);
    }

    /**
     * Override where method to capture conditions in $remoteConditions.
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): ApiQueryBuilder
    {
        // If only 2 arguments are passed, the operator is '='
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->remoteConditions[] = [
            'boolean' => $boolean,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        // We still call the parent for internal Eloquent tracking but in the end
        // the real data retrieval will happen via remote API.
        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Just a convenience to throw an exception if it's not found.
     */
    public function findOrFail($id, $columns = ['*'])
    {
        $record = $this->find($id, $columns);
        if (is_null($record)) {
            abort(404, 'Resource not found');
        }
        return $record;
    }

    /**
     * Attempt to retrieve a single record matching the given primary key.
     */
    public function find($id, $columns = ['*'])
    {
        // We can either call the remote API with path /{id}
        // or rely on the get() approach. Let's do direct approach here.

        $model = $this->getModel();
        $endpoint = $model->getTable() . '/' . $id;

        $client = $this->getApiClient();
        $response = $client->get($endpoint);

        if (!is_array($response)) {
            return null;
        }

        // Build the model instance
        $item = $model->newFromBuilder($response);
        $item->exists = true;
        return $item;
    }

    /**
     * Convert remote conditions to a query array for the remote API
     * * and retrieve results using GET.
     */
    public function get($columns = ['*'])
    {
        // Build the query parameters
        $queryParams = $this->buildRemoteQueryParams();

        // Get the model to figure out the "table" or resource endpoint
        $model = $this->getModel();
        $endpoint = $model->getTable();

        // Use the assigned client to fetch data
        $client = $this->getApiClient();
        $response = $client->get($endpoint, $queryParams);

        if (!is_array($response)) {
            // If the response is not a valid array, handle the error or return an empty collection
            return $model->newCollection();
        }

        // Convert each record in the response to a model instance
        $items = [];
        foreach ($response as $record) {
            $item = $model->newFromBuilder($record);
            $item->exists = true;
            $items[] = $item;
        }

        // Return an Eloquent Collection
        return $model->newCollection($items);
    }


    /**
     * Build remote query parameters from $this->remoteConditions.
     */
    protected function buildRemoteQueryParams(): array
    {
        $query = [];
        foreach ($this->remoteConditions as $cond) {
            // For simplicity, we encode them as: filter[status]=active, filter[price_gt]=1000, etc.
            // The naming can vary and you might build more advanced logic for operators:
            // e.g. if $cond['operator'] === '>', we do "filter[price_gt]"

            $column = $cond['column'];
            $operator = $cond['operator'];
            $value = $cond['value'];

            // Example: use suffixes for operators
            switch ($operator) {
                case '=':
                    $query["filter[$column]"] = $value;
                    break;
                case '>':
                    $query["filter[{$column}_gt]"] = $value;
                    break;
                case '<':
                    $query["filter[{$column}_lt]"] = $value;
                    break;
                case '>=':
                    $query["filter[{$column}_gte]"] = $value;
                    break;
                case '<=':
                    $query["filter[{$column}_lte]"] = $value;
                    break;
                case '!=':
                case '<>':
                    $query["filter[{$column}_neq]"] = $value;
                    break;
                default:
                    // If there's an unknown operator, we can either ignore or throw an exception.
                    break;
            }
        }

        return $query;
    }

    protected function getQueryParameters(): array
    {
        // TO-DO: building filters, sorting, eager loads etc.
        return $this->query->wheres;
    }

//    public function whereHas($relation, Closure $callback = null): ApiQueryBuilder|static
//    {
//        $relationQuery = new self($this->getModel()->{$relation}()->getQuery());
//        $callback($relationQuery = new static($this->getQuery()));
//
//        $conditions = $relation . ':' . http_build_query($relationConditions);
//        $this->remoteConditions[] = $conditions;
//
//        return $this;
//    }

    public function with($relations, $callback = null)
    {
        if (is_string($relations)) {
            $relations = explode(',', $relations);
        } elseif (is_array($relations = $this->parseWithRelations((array)$relations))) {
            foreach ($relations as $relation => $constraint) {
                $this->with[] = $relation;
            }
            return parent::with($relations);
        }
    }

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null): LengthAwarePaginator
    {
        $page = $page ?: request()->input('page', 1);

        $queryParams = $this->buildRemoteQueryParams();
        $queryParams['page'] = $page = $queryParams['page'] ?? $page;
        $queryParams['per_page'] = $perPage;

        $endpoint = $this->getModel()->getTable();
        $response = $this->getApiClient()->get($endpoint, $queryParams);

        $items = $this->hydrate($response['data']);
        return new LengthAwarePaginator($items, $response['total'], $perPage, $page);
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $typeOperator = $not ? 'not_in' : 'in';
        $this->remoteConditions["filter[{$column}_{$typeOperator}]"] = implode(',', $values);

        return $this;
    }

    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false): ApiQueryBuilder|static
    {
        $typeOperator = $not ? 'not_between' : 'between';
        $this->remoteConditions["filter[{$column}_{$typeOperator}]"] = implode(',', $values);

        return $this;
    }

    public function count($columns = '*')
    {
        $queryParams = $this->buildRemoteQueryParams(['aggregate' => 'count', 'column' => $columns]);

        $endpoint = $this->getModel()->getTable() . '/aggregate';

        $response = $this->getApiClient()->get($endpoint, $queryParams);

        return intval($response['count'] ?? 0);
    }

    public function sum($column)
    {
        $queryParams = $this->buildRemoteQueryParams(['aggregate' => 'sum', 'column' => $column]);
        $endpoint = $this->getModel()->getTable();

        $response = $this->getApiClient()->get($endpoint, $queryParams);

        return $response['sum'] ?? 0;
    }

    public function avg($column)
    {
        $queryParams = $this->buildRemoteQueryParams(['aggregate' => 'avg', 'column' => $column]);
        $response = $this->getApiClient()->get($this->getModel()->getTable(), $queryParams);

        return $response['avg'] ?? 0;
    }

    public function latest($column = 'created_at'): ApiQueryBuilder|static
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderBy($column, $direction = 'asc'): ApiQueryBuilder|static
    {
        $dir = strtolower($direction) === 'desc' ? '-' : '';

        if (isset($this->remoteConditions['sort'])) {
            $this->remoteConditions['sort'] .= ',' . ($dir === 'desc' ? '-' : '') . $column;
        } else {
            $this->remoteConditions['sort'] = ($dir === 'desc' ? '-' : '') . $column;
        }

        return $this;
    }

    public function oldest($column = 'created_at'): ApiQueryBuilder|static
    {
        return $this->orderBy($column, 'asc');
    }
}
