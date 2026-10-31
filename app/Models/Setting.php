<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_name', 'hourly_rate', 'monthly_fixed_costs', 'currency', 'timezone'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['hourly_rate' => 'integer', 'monthly_fixed_costs' => 'integer'];
    }
}
