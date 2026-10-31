<?php

namespace App\Models;

use App\Casts\DecimalNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['location_id', 'supply_id', 'unclassified', 'growing', 'mother', 'ready'])]
class SupplyBalance extends Model
{
    protected $table = 'supply_balances';

    protected function casts(): array
    {
        return ['location_id' => 'integer', 'supply_id' => 'integer', 'unclassified' => DecimalNumber::class, 'growing' => DecimalNumber::class, 'mother' => DecimalNumber::class, 'ready' => DecimalNumber::class];
    }
}
