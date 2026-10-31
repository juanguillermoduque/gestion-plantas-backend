<?php

namespace App\Services;

use App\Exceptions\BusinessRule;

class QuoteService
{
    /** @param array<string, mixed> $body @param array<int, array<string, mixed>> $products @return array<string, mixed> */
    public function calculate(array $body, array $products, int $defaultHourlyRate = 0, array $context = []): array
    {
        $rows = $body['items'] ?? [];
        if (! is_array($rows) || count($rows) < 1 || count($rows) > 30) {
            throw new BusinessRule('Agrega entre 1 y 30 productos al pedido.', 400);
        }
        $seen = [];
        $items = [];
        foreach ($rows as $row) {
            $id = $row['productId'] ?? '';
            if (! is_string($id) || in_array($id, $seen, true)) {
                throw new BusinessRule('Cada producto debe aparecer una sola vez; reúne sus cantidades.', 400);
            }
            $seen[] = $id;
            $product = null;
            foreach ($products as $candidate) {
                if ($candidate['id'] === $id && $candidate['active']) {
                    $product = $candidate;
                    break;
                }
            }
            if ($product === null) {
                throw new BusinessRule('Uno de los productos no existe o está inactivo.', 400);
            }
            $quantity = $this->integer($row['quantity'] ?? null, 'Cantidad', 1, 100000);
            $tierPrice = $product['basePrice'];
            $tiers = $product['tiers'];
            usort($tiers, fn (array $left, array $right): int => $left['minQty'] <=> $right['minQty']);
            foreach ($tiers as $tier) {
                if ($tier['minQty'] <= $quantity) {
                    $tierPrice = $tier['price'];
                }
            }
            $stockUnitsPerUnit = $product['stockUnitsPerUnit'] ?? $product['plantsPerUnit'] ?? 0;
            $items[] = ['productId' => $id, 'productName' => $product['name'], 'quantity' => $quantity, 'unitPrice' => $this->integer($row['unitPrice'] ?? $tierPrice, 'Precio', 1), 'unitCost' => $product['unitCost'], 'stockUnitsPerUnit' => $stockUnitsPerUnit, 'plantsPerUnit' => $stockUnitsPerUnit, 'minutes' => $product['minutes'], 'inventoryMode' => $product['inventoryMode'] ?? 'legacy', '_components' => $product['components'] ?? [], '_extraCost' => $product['extraCost'] ?? 0, '_legacySupply' => $product['legacySupply'] ?? ['id' => 0, 'name' => 'Inventario anterior', 'unit' => $context['inventoryUnitSingular'] ?? 'Unidad', 'isLegacy' => true]];
        }
        $hourlyRate = $this->integer($body['hourlyRate'] ?? $defaultHourlyRate, 'Valor de la hora', 0, 1000000);
        $subtotal = $minutesScaled = 0;
        $requirements = $costEntries = $itemCosts = [];
        foreach ($items as $index => &$item) {
            $subtotal = FixedPoint::add($subtotal, FixedPoint::multiply($item['quantity'], $item['unitPrice']));
            $minutesScaled = FixedPoint::add($minutesScaled, FixedPoint::multiply(FixedPoint::scaled($item['minutes'], 2, 'Minutos de preparación', 600), $item['quantity']));
            $rawCost = 0;
            if ($item['inventoryMode'] === 'legacy') {
                $rawCost = FixedPoint::multiply(FixedPoint::multiply(FixedPoint::scaled($item['unitCost'], 4, 'Costo anterior'), $item['quantity']), 1000);
                $costEntries['legacy:'.$index] = $rawCost;
                $legacyQuantity = FixedPoint::multiply(FixedPoint::scaled($item['stockUnitsPerUnit'], 3, 'Consumo anterior'), $item['quantity']);
                if ($legacyQuantity > 0) {
                    $legacy = $item['_legacySupply'];
                    $this->addRequirement($requirements, ['supplyId' => $legacy['id'], 'name' => $legacy['name'], 'unit' => $legacy['unit'], 'unitCost' => 0, 'kind' => 'stock', 'isLegacy' => true], $legacyQuantity, true);
                }
            } else {
                $extraRaw = FixedPoint::multiply(FixedPoint::multiply(FixedPoint::scaled($item['_extraCost'], 4, 'Costo adicional'), $item['quantity']), 1000);
                $costEntries['extra:'.$index] = $extraRaw;
                $rawCost = $extraRaw;
                foreach ($item['_components'] as $component) {
                    $quantityScaled = FixedPoint::multiply(FixedPoint::scaled($component['quantity'], 3, 'Cantidad de receta', 1000000), $item['quantity']);
                    $componentRaw = $this->addRequirement($requirements, $component, $quantityScaled, false);
                    $rawCost = FixedPoint::add($rawCost, $componentRaw);
                }
            }
            $itemCosts[$index] = $rawCost;
            unset($item['_components'], $item['_extraCost'], $item['_legacySupply']);
        }
        unset($item);
        foreach ($requirements as $supplyId => $requirement) {
            $costEntries['supply:'.$supplyId] = $requirement['_rawCost'];
        }
        $allocated = FixedPoint::allocate($costEntries);
        $allocatedItems = FixedPoint::allocate($itemCosts);
        foreach ($items as $index => &$item) {
            $item['materialCost'] = $allocatedItems[$index];
        }
        unset($item);
        $materials = array_sum($allocated);
        $productExtras = $legacyMaterialCost = $componentCost = $legacyQuantityScaled = 0;
        foreach ($allocated as $key => $cost) {
            if (str_starts_with($key, 'legacy:')) {
                $legacyMaterialCost += $cost;
            } elseif (str_starts_with($key, 'extra:')) {
                $productExtras += $cost;
            } else {
                $componentCost += $cost;
            }
        }
        foreach ($requirements as $supplyId => &$requirement) {
            $requirement['quantity'] = FixedPoint::number($requirement['_quantity'], 3);
            $requirement['inventoryOnlyQuantity'] = FixedPoint::number($requirement['_inventoryOnlyQuantity'], 3);
            $requirement['costedQuantity'] = FixedPoint::number($requirement['_quantity'] - $requirement['_inventoryOnlyQuantity'], 3);
            $requirement['costQuantity'] = $requirement['costedQuantity'];
            $requirement['totalCost'] = $allocated['supply:'.$supplyId];
            $requirement['inventoryOnly'] = $requirement['_quantity'] === $requirement['_inventoryOnlyQuantity'];
            if ($requirement['isLegacy'] && $requirement['kind'] === 'stock') {
                $legacyQuantityScaled = FixedPoint::add($legacyQuantityScaled, $requirement['_quantity']);
            }
            unset($requirement['_quantity'], $requirement['_inventoryOnlyQuantity'], $requirement['_rawCost']);
        }
        unset($requirement);
        $requirements = array_values($requirements);
        $stockUnitsNeeded = FixedPoint::number($legacyQuantityScaled, 3);
        $assemblyMinutes = FixedPoint::number($minutesScaled, 2);
        $labor = FixedPoint::roundRatio(FixedPoint::multiply($minutesScaled, $hourlyRate), 6000);
        $deliveryCost = $this->integer($body['deliveryCost'] ?? 0, 'Costo de entrega');
        $deliveryCharged = $this->integer($body['deliveryCharged'] ?? 0, 'Entrega cobrada');
        $adCost = $this->integer($body['adCost'] ?? 0, 'Publicidad');
        $otherCost = $this->integer($body['otherCost'] ?? 0, 'Otros costos');
        $discount = $this->integer($body['discount'] ?? 0, 'Descuento');
        if ($discount > $subtotal) {
            throw new BusinessRule('El descuento adicional no puede superar el valor de los productos.', 400);
        }
        $total = $subtotal - $discount + $deliveryCharged;
        $costTotal = $materials + $labor + $deliveryCost + $adCost + $otherCost;
        $contribution = $total - $costTotal;

        return compact('items', 'requirements', 'productExtras', 'legacyMaterialCost', 'componentCost', 'hourlyRate', 'subtotal', 'materials', 'labor', 'assemblyMinutes', 'stockUnitsNeeded', 'deliveryCost', 'deliveryCharged', 'adCost', 'otherCost', 'discount', 'total', 'costTotal', 'contribution') + ['inventoryMode' => 'bom', 'plantsNeeded' => $stockUnitsNeeded, 'contributionMargin' => $total > 0 ? $contribution / $total : 0, 'moneyPrecision' => 0] + $context;
    }

    /** @param array<int, array<string, mixed>> $requirements @param array<string, mixed> $component */
    private function addRequirement(array &$requirements, array $component, int $quantity, bool $inventoryOnly): int
    {
        $id = (int) ($component['supplyId'] ?? $component['id'] ?? 0);
        $price = $inventoryOnly ? 0 : FixedPoint::scaled($component['unitCost'], 4, 'Costo del insumo');
        $rawCost = FixedPoint::multiply($quantity, $price);
        if (! isset($requirements[$id])) {
            $requirements[$id] = ['supplyId' => $id, 'name' => $component['name'] ?? $component['supplyName'], 'unit' => $component['unit'], 'kind' => $component['kind'], 'unitCost' => FixedPoint::number($price, 4), 'isLegacy' => $component['isLegacy'] ?? false, '_quantity' => 0, '_inventoryOnlyQuantity' => 0, '_rawCost' => 0];
        }
        $requirements[$id]['_quantity'] = FixedPoint::add($requirements[$id]['_quantity'], $quantity);
        if ($requirements[$id]['_quantity'] > 1000000000000) {
            throw new BusinessRule('El consumo de un insumo supera el límite de mil millones de unidades por pedido.', 400);
        }
        $requirements[$id]['_rawCost'] = FixedPoint::add($requirements[$id]['_rawCost'], $rawCost);
        if ($inventoryOnly) {
            $requirements[$id]['_inventoryOnlyQuantity'] = FixedPoint::add($requirements[$id]['_inventoryOnlyQuantity'], $quantity);
        } else {
            $requirements[$id]['unitCost'] = FixedPoint::number($price, 4);
        }

        return $rawCost;
    }

    private function integer(mixed $value, string $label, int $min = 0, int $max = 1000000000): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $min || (float) $value > $max || floor((float) $value) !== (float) $value) {
            throw new BusinessRule($label.': ingresa un número entero válido.', 400);
        }

        return (int) $value;
    }
}
