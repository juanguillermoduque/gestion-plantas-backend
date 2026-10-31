<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['location_id', 'user_id', 'date', 'category', 'amount', 'note'])]
class Expense extends Model
{
    protected function casts(): array
    {
        return ['location_id' => 'integer', 'amount' => 'integer', 'date' => 'date:Y-m-d'];
    }
}
