<?php

namespace App\Services;

use App\Exceptions\BusinessRule;
use App\Models\Product;
use App\Models\Supply;

class RecipeService
{
    /** @return array<int, array<string, mixed>> */
    public function catalog(): array
    {
        $supplies = Supply::all()->keyBy('id')->all();

        return Product::orderBy('id')->get()->map(fn (Product $product): array => $this->view($product, $supplies))->all();
    }

    /** @param array<int, Supply>|null $supplies @return array<string, mixed> */
    public function view(Product $product, ?array $supplies = null): array
    {
        $supplies ??= Supply::all()->keyBy('id')->all();
        $mode = $product->inventory_mode ?? 'legacy';
        $components = $product->components;
        $unitCost = $product->unit_cost;
        $legacyUnits = $product->plants_per_unit;
        if ($mode === 'bom') {
            $rawCost = FixedPoint::multiply(FixedPoint::scaled($product->extra_cost, 4, 'Costo adicional'), 1000);
            $components = [];
            $legacyUnitsScaled = 0;
            foreach ($product->components as $component) {
                $supply = $supplies[$component['supplyId']] ?? null;
                if ($supply === null) {
                    throw new BusinessRule('Un insumo de la receta ya no está disponible. Revisa el catálogo antes de cotizar.', 400);
                }
                $quantity = FixedPoint::scaled($component['quantity'], 3, 'Cantidad de receta', 1000000);
                $rawCost = FixedPoint::add($rawCost, FixedPoint::multiply($quantity, FixedPoint::scaled($supply->unit_cost, 4, 'Costo del insumo')));
                if ($supply->is_legacy && $supply->kind === 'stock') {
                    $legacyUnitsScaled = FixedPoint::add($legacyUnitsScaled, $quantity);
                }
                $components[] = ['supplyId' => $supply->id, 'quantity' => FixedPoint::number($quantity, 3), 'name' => $supply->name, 'supplyName' => $supply->name, 'unit' => $supply->unit, 'unitCost' => $supply->unit_cost, 'kind' => $supply->kind, 'active' => $supply->active, 'isLegacy' => $supply->is_legacy];
            }
            $unitCost = FixedPoint::number(FixedPoint::roundRatio($rawCost, 1000), 4);
            $legacyUnits = FixedPoint::number($legacyUnitsScaled, 3);
        }
        $legacy = collect($supplies)->first(fn (Supply $supply): bool => $supply->is_legacy);

        return ['id' => $product->id, 'name' => $product->name, 'inventoryMode' => $mode, 'recipeConfigured' => $mode === 'bom', 'extraCost' => $mode === 'bom' ? $product->extra_cost : 0, 'stockUnitsPerUnit' => $legacyUnits, 'plantsPerUnit' => $legacyUnits, 'unitCost' => $unitCost, 'basePrice' => $product->base_price, 'minutes' => $product->minutes, 'components' => $components, 'tiers' => $product->tiers, 'active' => $product->active, 'legacySupply' => $legacy ? ApiView::supply($legacy) : null];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function attributes(array $data, ?Product $existing = null): array
    {
        $mode = $data['inventoryMode'] ?? $existing?->inventory_mode ?? 'bom';
        if ($mode === 'legacy') {
            if ($existing === null || $existing->inventory_mode !== 'legacy') {
                throw new BusinessRule('Los productos nuevos deben usar una receta de insumos. No se puede volver al modo anterior después de configurar la receta.', 400);
            }
            if (FixedPoint::scaled($data['extraCost'] ?? 0, 4, 'Costo adicional') !== 0) {
                throw new BusinessRule('Configura la receta de insumos para añadir costos adicionales fraccionados.', 400);
            }
            $components = array_map(fn (array $component): array => ['name' => trim($component['name']), 'quantity' => (float) $component['quantity'], 'unitCost' => (int) $component['unitCost']], $data['components']);
            $unitCost = (int) round(array_sum(array_map(fn (array $component): float => $component['quantity'] * $component['unitCost'], $components)));
            if ($unitCost > 1000000000) {
                throw new BusinessRule('El costo del producto supera el límite permitido.', 400);
            }

            return ['inventory_mode' => 'legacy', 'extra_cost' => 0, 'unit_cost' => $unitCost, 'plants_per_unit' => (int) ($data['stockUnitsPerUnit'] ?? $existing->plants_per_unit), 'components' => $components];
        }
        $extra = FixedPoint::scaled($data['extraCost'] ?? 0, 4, 'Costo adicional');
        $rawCost = FixedPoint::multiply($extra, 1000);
        $components = [];
        $previous = $existing?->inventory_mode === 'bom' ? collect($existing->components)->keyBy('supplyId') : collect();
        foreach ($data['components'] as $component) {
            $supply = Supply::findOrFail((int) $component['supplyId']);
            $quantity = FixedPoint::scaled($component['quantity'], 3, 'Cantidad de receta', 1000000);
            if ($quantity === 0) {
                throw new BusinessRule('La cantidad de cada insumo debe ser mayor que cero.', 400);
            }
            $old = $previous->get($supply->id);
            if (! $supply->active && ($old === null || FixedPoint::scaled($old['quantity'], 3, 'Cantidad anterior', 1000000) !== $quantity)) {
                throw new BusinessRule('Los insumos inactivos no se pueden añadir a recetas nuevas ni aumentar su consumo. Reactiva el insumo o reemplázalo.', 400);
            }
            $rawCost = FixedPoint::add($rawCost, FixedPoint::multiply($quantity, FixedPoint::scaled($supply->unit_cost, 4, 'Costo del insumo')));
            $components[] = ['supplyId' => $supply->id, 'quantity' => FixedPoint::number($quantity, 3)];
        }

        return ['inventory_mode' => 'bom', 'extra_cost' => FixedPoint::number($extra, 4), 'unit_cost' => FixedPoint::roundRatio($rawCost, FixedPoint::COST_SCALE), 'plants_per_unit' => 0, 'components' => $components];
    }
}
