<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;

class RemoteQueryProcessor extends MySqlProcessor
{
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values, $sequence);

        $id = $query->getConnection()->getLastInsertId();

        return is_numeric($id) ? (int) $id : $id;
    }
}
