<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['location_id', 'user_id', 'type', 'from_bucket', 'to_bucket', 'quantity', 'note'])]
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['location_id' => 'integer', 'quantity' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
