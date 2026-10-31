<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'user_id', 'customer_name', 'phone', 'location', 'due_date', 'source', 'status', 'notes', 'totals', 'plants_needed', 'delivered_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return ['totals' => 'array', 'plants_needed' => 'integer', 'due_date' => 'date:Y-m-d', 'delivered_at' => 'immutable_datetime'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
