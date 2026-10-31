<?php

namespace App\Services;

use App\Exceptions\BusinessRule;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\SupplyBalance;
use App\Models\SupplyCostChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplyService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Supply
    {
        return DB::transaction(function () use ($data): Supply {
            Setting::query()->lockForUpdate()->findOrFail(1);
            $attributes = $this->attributes($data);
            $this->validateMinimum($attributes);
            $supply = Supply::create($attributes + ['active' => true, 'is_legacy' => false]);
            if ($supply->kind === 'stock') {
                foreach (Location::query()->orderBy('id')->pluck('id') as $locationId) {
                    $this->createBalance($supply->id, $locationId);
                }
            }

            return $supply->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(Supply $supply, array $data, User $user): Supply
    {
        return DB::transaction(function () use ($supply, $data, $user): Supply {
            Setting::query()->lockForUpdate()->findOrFail(1);
            $locked = Supply::query()->lockForUpdate()->findOrFail($supply->id);
            $attributes = $this->attributes($data);
            $kind = $attributes['kind'] ?? $locked->kind;
            $unit = $attributes['unit'] ?? $locked->unit;
            $hasSemanticChange = $kind !== $locked->kind || $unit !== $locked->unit;
            if ($hasSemanticChange && $this->hasUsage($locked)) {
                throw new BusinessRule('El tipo y la unidad no pueden cambiarse en un insumo con existencias, recetas o historial. Crea otro insumo para usar una unidad diferente.');
            }
            if ($locked->is_legacy && $kind !== 'stock') {
                throw new BusinessRule('El inventario anterior conserva su tipo físico para proteger los pedidos existentes.');
            }
            $this->validateMinimum(['kind' => $kind, 'min_stock' => $attributes['min_stock'] ?? $locked->min_stock]);
            $before = ApiView::supply($locked);
            $locked->fill($attributes);
            if ($locked->isDirty()) {
                $locked->save();
                SupplyCostChange::create(['supply_id' => $locked->id, 'user_id' => $user->id, 'before' => $before, 'after' => ApiView::supply($locked)]);
            }
            if ($kind === 'stock') {
                foreach (Location::query()->orderBy('id')->pluck('id') as $locationId) {
                    $this->createBalance($locked->id, $locationId);
                }
            }

            return $locked;
        }, 3);
    }

    public function createBalancesForLocation(int $locationId): void
    {
        DB::transaction(function () use ($locationId): void {
            Setting::query()->lockForUpdate()->findOrFail(1);
            foreach (Supply::query()->where('kind', 'stock')->orderBy('id')->pluck('id') as $supplyId) {
                $this->createBalance($supplyId, $locationId);
            }
        }, 3);
    }

    public function hasFinancialHistory(): bool
    {
        return Supply::query()->where('unit_cost', '>', 0)->exists()
            || SupplyCostChange::exists()
            || InventoryMovement::exists();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $result = [];
        foreach (['name', 'category', 'unit', 'kind'] as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field] = trim($data[$field] ?? '');
            }
        }
        if (array_key_exists('unitCost', $data)) {
            $result['unit_cost'] = FixedPoint::number(FixedPoint::scaled($data['unitCost'], 4, 'Costo unitario'), 4);
        }
        if (array_key_exists('minStock', $data)) {
            $result['min_stock'] = FixedPoint::number(FixedPoint::scaled($data['minStock'], 3, 'Inventario mínimo'), 3);
        }
        if (array_key_exists('active', $data)) {
            $result['active'] = (bool) $data['active'];
        }

        return $result;
    }

    /** @param array<string, mixed> $attributes */
    private function validateMinimum(array $attributes): void
    {
        if (($attributes['kind'] ?? 'stock') === 'expense' && (float) ($attributes['min_stock'] ?? 0) > 0) {
            throw new BusinessRule('Los conceptos no inventariables no tienen un mínimo de existencias.', 400);
        }
    }

    private function createBalance(int $supplyId, int $locationId): void
    {
        SupplyBalance::firstOrCreate(['supply_id' => $supplyId, 'location_id' => $locationId], ['unclassified' => 0, 'growing' => 0, 'mother' => 0, 'ready' => 0]);
    }

    private function hasUsage(Supply $supply): bool
    {
        return SupplyBalance::query()->where('supply_id', $supply->id)
            ->where(fn ($query) => $query->where('unclassified', '!=', 0)->orWhere('growing', '!=', 0)->orWhere('mother', '!=', 0)->orWhere('ready', '!=', 0))->exists()
            || InventoryMovement::where('supply_id', $supply->id)->exists()
            || SupplyCostChange::where('supply_id', $supply->id)->exists()
            || Product::query()->where('inventory_mode', 'bom')->whereJsonContains('components', [['supplyId' => $supply->id]])->exists()
            || Order::query()->whereJsonContains('totals->requirements', [['supplyId' => $supply->id]])->exists()
            || ($supply->is_legacy && Product::where('inventory_mode', 'legacy')->exists());
    }
}
