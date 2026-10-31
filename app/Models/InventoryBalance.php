<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['unclassified', 'growing', 'mother', 'ready'])]
class InventoryBalance extends Model
{
    protected function casts(): array
    {
        return ['unclassified' => 'integer', 'growing' => 'integer', 'mother' => 'integer', 'ready' => 'integer'];
    }
}
