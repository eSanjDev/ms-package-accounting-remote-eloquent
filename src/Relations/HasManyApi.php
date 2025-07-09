<?php

namespace Esanj\RemoteEloquent\Relations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class HasManyApi extends ApiRelation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct($query, $parent, $foreignKey, $localKey)
    {
        parent::__construct($query, $parent);
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    public function getResults()
    {
        $keyValue = $this->parent->{$this->localKey};

        $response = $this->client->request('get', $this->related->endpoint(), [
            'filters' => [
                ['column' => $this->foreignKey, 'operator' => '=', 'value' => $keyValue]
            ]
        ]);

        return $response->successful() ?
            $this->toCollection($response->json()) : collect();
    }

    protected function toCollection(array $items): Collection
    {
        return collect($items)->map(fn($item) => $this->related->newInstance($item, true));
    }

    public function addConstraints()
    {
        // TODO: Implement addConstraints() method.
    }

    public function addEagerConstraints(array $models)
    {
        // TODO: Implement addEagerConstraints() method.
    }

    public function initRelation(array $models, $relation)
    {
        // TODO: Implement initRelation() method.
    }

    public function match(array $models, EloquentCollection $results, $relation)
    {
        // TODO: Implement match() method.
    }
}
