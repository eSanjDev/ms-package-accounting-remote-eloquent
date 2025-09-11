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

    public function getApiClient(): ClientInterface
    {
        return $this->model->getApiClient();
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
        $model = $this->getModel();

        $client = $this->getApiClient();

        $sql = $this->toSql() . " WHERE " . $model->getKeyName() . "=" . $id;
        $response = $client->run($sql);

        if (!is_array($response) || empty($response)) {
            return null;
        }

        // Build the model instance
        $item = $model->newFromBuilder($response[0] ?? []);
        $item->exists = true;
        return $item;
    }

    /**
     * Convert remote conditions to a query array for the remote API
     * * and retrieve results using GET.
     */
    public function get($columns = ['*'])
    {
        // Get the model to figure out the "table" or resource endpoint
        $model = $this->getModel();

        // Use the assigned client to fetch data
        $client = $this->getApiClient();
        $response = $client->run($this->toRawSql());

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

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null): LengthAwarePaginator
    {
        $page = $page ?: request()->input('page', 1);

        $queryParams = $this->buildRemoteQueryParams();
        $queryParams['page'] = $page = $queryParams['page'] ?? $page;
        $queryParams['per_page'] = $perPage;

        $endpoint = $this->getModel()->getTable();
        $response = $this->getApiClient()->run($endpoint, $queryParams);

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

    public function count($columns = '*'): int
    {
        $sql = 'SELECT COUNT(*) as count FROM ' . $this->getModel()->getTable();

        $response = $this->getApiClient()->run($sql);

        return intval($response[0]['count'] ?? 0);
    }

    public function sum($column): int
    {
        $sql = 'SELECT SUM(' . $column . ') as sum FROM ' . $this->getModel()->getTable();
        $response = $this->getApiClient()->run($sql);

        return (int)$response[0]['sum'] ?? 0;
    }

    public function avg($column): int
    {
        $sql = 'SELECT AVG(' . $column . ') as avg FROM ' . $this->getModel()->getTable();
        $response = $this->getApiClient()->run($sql);

        return (int)$response[0]['avg'] ?? 0;
    }
}
