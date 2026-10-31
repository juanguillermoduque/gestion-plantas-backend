<?php

namespace App\Services;

use App\Exceptions\BusinessRule;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public const BUCKETS = ['unclassified', 'growing', 'mother', 'ready'];

    public function __construct(private readonly LocationService $locations) {}

    public function lock(int $locationId): InventoryBalance
    {
        return InventoryBalance::query()->where('location_id', $locationId)->lockForUpdate()->firstOrFail();
    }

    public function reserved(int $locationId, ?int $exceptOrder = null): int
    {
        return (int) Order::query()->where('location_id', $locationId)->where('status', 'confirmed')->when($exceptOrder !== null, fn ($query) => $query->where('id', '!=', $exceptOrder))->sum('plants_needed');
    }

    /** @return array<string, int> */
    public function snapshot(int $locationId, ?InventoryBalance $balance = null): array
    {
        $balance ??= InventoryBalance::where('location_id', $locationId)->firstOrFail();
        $reserved = $this->reserved($locationId);
        $result = ['unclassified' => $balance->unclassified, 'growing' => $balance->growing, 'mother' => $balance->mother, 'ready' => $balance->ready];

        return $result + ['locationId' => $locationId, 'reserved' => $reserved, 'available' => $balance->ready - $reserved, 'total' => array_sum($result)];
    }

    /** @param array<string, mixed> $input */
    public function move(array $input, User $user): InventoryMovement
    {
        return DB::transaction(function () use ($input, $user): InventoryMovement {
            $location = $this->locations->resolve($input['locationId'] ?? null, true, true);
            $balance = $this->lock($location->id);
            $type = $input['type'];
            $from = in_array($type, ['transfer', 'loss'], true) ? $input['from'] : null;
            $to = in_array($type, ['transfer', 'add'], true) ? $input['to'] : null;
            $quantity = (int) $input['quantity'];
            if ($from !== null && $from === $to) {
                throw new BusinessRule('El origen y el destino deben ser diferentes.', 400);
            }
            if ($from !== null) {
                $available = $from === 'ready' ? $balance->ready - $this->reserved($location->id) : $balance->{$from};
                if ($quantity > $available) {
                    throw new BusinessRule('No hay suficientes existencias disponibles en ese grupo; las reservadas están comprometidas.');
                }
                $balance->{$from} -= $quantity;
            }
            if ($to !== null) {
                if ($balance->{$to} + $quantity > 1000000000) {
                    throw new BusinessRule('La cantidad supera el límite del inventario.', 400);
                }
                $balance->{$to} += $quantity;
            }
            $balance->save();

            return InventoryMovement::create(['location_id' => $location->id, 'user_id' => $user->id, 'type' => $type, 'from_bucket' => $from, 'to_bucket' => $to, 'quantity' => $quantity, 'note' => $input['note'] ?? '']);
        }, 3);
    }
}
