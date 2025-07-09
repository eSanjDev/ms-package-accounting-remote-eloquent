<?php

namespace Esanj\RemoteEloquent\Trait;

trait RemoteJoinable
{
    public function joinRemoteRelation(string $relation, string $foreignKey)
    {
        // Fetch primary data
        $primaryData = $this->get();

        // Extract and filter IDs for joining
        $relationIds = $primaryData->pluck($foreignKey)->filter()->unique()->all();

        if (empty($relationIds)) {
            return $primaryData;
        }

        // Fetch related data
        $relatedInstance = $this->{$relation}()->getRelated();
        $relatedData = $relatedInstance->whereIn('id', $relationIds)->get()->keyBy('id');

        // Map related data to primary data
        $primaryData->transform(function ($item) use ($relatedData, $relation, $foreignKey) {
            $item->{$relation} = $relatedData->get($item->{$foreignKey});
            return $item;
        });

        return $primaryData;
    }
}
