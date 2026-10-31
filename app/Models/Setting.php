<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_name', 'business_type', 'city', 'country', 'delivery_coverage', 'inventory_unit', 'inventory_unit_singular', 'bucket_labels', 'hourly_rate', 'monthly_fixed_costs', 'currency', 'locale', 'timezone'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['hourly_rate' => 'integer', 'monthly_fixed_costs' => 'integer', 'bucket_labels' => 'array'];
    }
}
