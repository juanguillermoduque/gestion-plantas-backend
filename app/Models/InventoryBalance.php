<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

#[Fillable(['location_id', 'unclassified', 'growing', 'mother', 'ready'])]
class InventoryBalance extends SupplyBalance
{
    protected static function booted(): void
    {
        static::addGlobalScope('legacy', fn (Builder $query) => $query->where('supply_balances.supply_id', Supply::legacy()->id));
    }

    public function save(array $options = []): bool
    {
        $this->supply_id ??= Supply::legacy()->id;

        return parent::save($options);
    }
}
