<?php

namespace App\Models;

use App\Casts\DecimalNumber;
use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'category', 'unit', 'kind', 'unit_cost', 'min_stock', 'active', 'is_legacy'])]
class Supply extends Model
{
    /** @use HasFactory<SupplyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['unit_cost' => DecimalNumber::class, 'min_stock' => DecimalNumber::class, 'active' => 'boolean', 'is_legacy' => 'boolean'];
    }

    public static function legacy(): self
    {
        return self::query()->where('is_legacy', true)->firstOrFail();
    }
}
