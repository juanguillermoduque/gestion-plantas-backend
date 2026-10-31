<?php

namespace App\Models;

use App\Casts\DecimalNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['location_id', 'supply_id', 'user_id', 'type', 'from_bucket', 'to_bucket', 'quantity', 'note'])]
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    public function save(array $options = []): bool
    {
        $this->supply_id ??= Supply::legacy()->id;

        return parent::save($options);
    }

    protected function casts(): array
    {
        return ['location_id' => 'integer', 'supply_id' => 'integer', 'quantity' => DecimalNumber::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
