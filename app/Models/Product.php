<?php

namespace App\Models;

use App\Casts\DecimalNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'plants_per_unit', 'unit_cost', 'base_price', 'minutes', 'components', 'tiers', 'active', 'inventory_mode', 'extra_cost'])]
class Product extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['plants_per_unit' => 'integer', 'unit_cost' => 'integer', 'base_price' => 'integer', 'minutes' => 'float', 'components' => 'array', 'tiers' => 'array', 'active' => 'boolean', 'extra_cost' => DecimalNumber::class];
    }
}
