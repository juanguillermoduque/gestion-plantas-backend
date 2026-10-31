<?php

namespace App\Services;

use App\Exceptions\BusinessRule;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Supply;
use App\Models\SupplyBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public const BUCKETS = ['unclassified', 'growing', 'mother', 'ready'];

    public function __construct(private readonly LocationService $locations) {}

    public function lock(int $locationId, ?int $supplyId = null): SupplyBalance
    {
        $supplyId ??= Supply::legacy()->id;
        SupplyBalance::firstOrCreate(['location_id' => $locationId, 'supply_id' => $supplyId], ['unclassified' => 0, 'growing' => 0, 'mother' => 0, 'ready' => 0]);

        return SupplyBalance::query()->where('location_id', $locationId)->where('supply_id', $supplyId)->lockForUpdate()->firstOrFail();
    }

    public function reserved(int $locationId, ?int $exceptOrder = null): int|float
    {
        $reserved = $this->reservationMap($locationId, $exceptOrder);

        return FixedPoint::number($reserved[Supply::legacy()->id] ?? 0, 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(int $locationId, ?SupplyBalance $balance = null): array
    {
        $legacy = Supply::legacy();
        $balance ??= SupplyBalance::where('location_id', $locationId)->where('supply_id', $legacy->id)->first();

        return $this->balanceView($legacy, $locationId, $balance, $this->reservationMap($locationId)[$legacy->id] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function balances(int $locationId): array
    {
        $balances = SupplyBalance::where('location_id', $locationId)->get()->keyBy('supply_id');
        $reserved = $this->reservationMap($locationId);

        return Supply::where('kind', 'stock')->orderBy('id')->get()->map(fn (Supply $supply): array => $this->balanceView($supply, $locationId, $balances->get($supply->id), $reserved[$supply->id] ?? 0))->all();
    }

    /** @return array<string, mixed> */
    private function balanceView(Supply $supply, int $locationId, ?SupplyBalance $balance, int $reserved): array
    {
        $total = 0;
        $result = [];
        foreach (self::BUCKETS as $bucket) {
            $quantity = FixedPoint::scaled($balance?->{$bucket} ?? 0, 3, 'Existencias');
            $total = FixedPoint::add($total, $quantity);
            $result[$bucket] = FixedPoint::number($quantity, 3);
        }
        $available = FixedPoint::scaled($balance?->ready ?? 0, 3, 'Existencias listas') - $reserved;

        return $result + ['locationId' => $locationId, 'supplyId' => $supply->id, 'name' => $supply->name, 'unit' => $supply->unit, 'kind' => $supply->kind, 'unitCost' => $supply->unit_cost, 'minStock' => $supply->min_stock, 'active' => $supply->active, 'isLegacy' => $supply->is_legacy, 'reserved' => FixedPoint::number($reserved, 3), 'available' => FixedPoint::number($available, 3), 'total' => FixedPoint::number($total, 3), 'belowMinimum' => $available < FixedPoint::scaled($supply->min_stock, 3, 'Mínimo')];
    }

    /** @return array<int, int> */
    public function reservationMap(int $locationId, ?int $exceptOrder = null): array
    {
        $result = [];
        $orders = Order::where('location_id', $locationId)->where('status', 'confirmed')->when($exceptOrder !== null, fn ($query) => $query->where('id', '!=', $exceptOrder))->get();
        foreach ($orders as $order) {
            foreach ($this->demands($this->requirements($order)) as $supplyId => $quantity) {
                $result[$supplyId] = FixedPoint::add($result[$supplyId] ?? 0, $quantity);
            }
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function requirements(Order $order): array
    {
        if ($order->inventory_mode !== 'legacy') {
            return $order->totals['requirements'] ?? [];
        }
        if ($order->plants_needed <= 0) {
            return [];
        }
        $legacy = Supply::legacy();

        return [['supplyId' => $legacy->id, 'name' => $legacy->name, 'unit' => $legacy->unit, 'kind' => 'stock', 'quantity' => $order->plants_needed, 'unitCost' => 0, 'totalCost' => 0, 'isLegacy' => true, 'inventoryOnly' => true]];
    }

    /** @param array<int, array<string, mixed>> $requirements @return array<int, int> */
    public function demands(array $requirements): array
    {
        $result = [];
        foreach ($requirements as $requirement) {
            if ($requirement['kind'] !== 'stock') {
                continue;
            }
            $id = (int) $requirement['supplyId'];
            $result[$id] = FixedPoint::add($result[$id] ?? 0, FixedPoint::scaled($requirement['quantity'], 3, 'Consumo de inventario'));
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /** @param array<int, array<string, mixed>> $requirements @return array<int, SupplyBalance> */
    public function lockRequirements(int $locationId, array $requirements): array
    {
        $balances = [];
        foreach ($this->demands($requirements) as $supplyId => $quantity) {
            Supply::query()->lockForUpdate()->findOrFail($supplyId);
            $balances[$supplyId] = $this->lock($locationId, $supplyId);
        }

        return $balances;
    }

    /** @param array<int, array<string, mixed>> $requirements @return array<string, mixed> */
    public function availability(int $locationId, array $requirements, ?int $exceptOrder = null): array
    {
        $reserved = $this->reservationMap($locationId, $exceptOrder);
        $balances = SupplyBalance::where('location_id', $locationId)->get()->keyBy('supply_id');
        $shortages = [];
        foreach ($this->demands($requirements) as $supplyId => $required) {
            $available = FixedPoint::scaled($balances->get($supplyId)?->ready ?? 0, 3, 'Existencias listas') - ($reserved[$supplyId] ?? 0);
            if ($required <= $available) {
                continue;
            }
            $requirement = collect($requirements)->firstWhere('supplyId', $supplyId);
            $shortages[] = ['supplyId' => $supplyId, 'name' => $requirement['name'], 'unit' => $requirement['unit'], 'required' => FixedPoint::number($required, 3), 'available' => FixedPoint::number(max(0, $available), 3), 'shortage' => FixedPoint::number($required - max(0, $available), 3)];
        }

        return ['shortages' => $shortages, 'canConfirm' => $shortages === []];
    }

    /** @param array<string, mixed> $input */
    public function move(array $input, User $user): InventoryMovement
    {
        return DB::transaction(function () use ($input, $user): InventoryMovement {
            $location = $this->locations->resolve($input['locationId'] ?? null, true, true);
            $supplyId = $input['supplyId'] ?? Supply::legacy()->id;
            $supply = Supply::query()->lockForUpdate()->findOrFail($supplyId);
            if ($supply->kind !== 'stock') {
                throw new BusinessRule('Los conceptos de gasto no tienen existencias ni movimientos de inventario.', 400);
            }
            if ($input['type'] === 'add' && ! $supply->active) {
                throw new BusinessRule('Reactiva el insumo antes de registrar nuevas entradas. Las bajas y transferencias internas del saldo existente siguen disponibles.');
            }
            $balance = $this->lock($location->id, $supply->id);
            $type = $input['type'];
            $from = in_array($type, ['transfer', 'loss'], true) ? $input['from'] : null;
            $to = in_array($type, ['transfer', 'add'], true) ? $input['to'] : null;
            $quantity = FixedPoint::scaled($input['quantity'], 3, 'Cantidad', 1000000);
            if ($from !== null && $from === $to) {
                throw new BusinessRule('El origen y el destino deben ser diferentes.', 400);
            }
            if ($from !== null) {
                $reserved = $this->reservationMap($location->id)[$supply->id] ?? 0;
                $available = FixedPoint::scaled($balance->{$from}, 3, 'Existencias') - ($from === 'ready' ? $reserved : 0);
                if ($quantity > $available) {
                    throw new BusinessRule('No hay suficientes existencias disponibles en ese grupo; las reservadas están comprometidas.');
                }
                $balance->{$from} = FixedPoint::number(FixedPoint::scaled($balance->{$from}, 3, 'Existencias') - $quantity, 3);
            }
            if ($to !== null) {
                $total = FixedPoint::add(FixedPoint::scaled($balance->{$to}, 3, 'Existencias'), $quantity);
                if ($total > 1000000000000) {
                    throw new BusinessRule('La cantidad supera el límite del inventario.', 400);
                }
                $balance->{$to} = FixedPoint::number($total, 3);
            }
            $balance->save();

            return InventoryMovement::create(['location_id' => $location->id, 'supply_id' => $supply->id, 'user_id' => $user->id, 'type' => $type, 'from_bucket' => $from, 'to_bucket' => $to, 'quantity' => FixedPoint::number($quantity, 3), 'note' => $input['note'] ?? '']);
        }, 3);
    }
}
