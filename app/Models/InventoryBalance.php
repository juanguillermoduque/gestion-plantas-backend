<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['location_id', 'unclassified', 'growing', 'mother', 'ready'])]
class InventoryBalance extends Model
{
    protected function casts(): array
    {
        return ['location_id' => 'integer', 'unclassified' => 'integer', 'growing' => 'integer', 'mother' => 'integer', 'ready' => 'integer'];
    }
}
