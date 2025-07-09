<?php

namespace Esanj\RemoteEloquent\Relations;

use Illuminate\Database\Eloquent\Relations\Relation;

abstract class ApiRelation extends Relation
{
    protected $client;

    public function __construct($query, $parent)
    {
        parent::__construct($query, $parent);
        $this->client = $parent->getApiClient();
    }

    abstract public function getResults();
}
