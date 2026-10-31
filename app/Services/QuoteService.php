<?php

namespace App\Services;

use App\Exceptions\BusinessRule;

class QuoteService
{
    /** @param array<string, mixed> $body @param array<int, array<string, mixed>> $products @return array<string, mixed> */
    public function calculate(array $body, array $products, int $defaultHourlyRate = 10000): array
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
            $items[] = ['productId' => $id, 'productName' => $product['name'], 'quantity' => $quantity, 'unitPrice' => $this->integer($row['unitPrice'] ?? $tierPrice, 'Precio', 1), 'unitCost' => $product['unitCost'], 'plantsPerUnit' => $product['plantsPerUnit'], 'minutes' => $product['minutes']];
        }
        $hourlyRate = $this->integer($body['hourlyRate'] ?? $defaultHourlyRate, 'Valor de la hora', 0, 1000000);
        $subtotal = $materials = $plantsNeeded = 0;
        $assemblyMinutes = 0.0;
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unitPrice'];
            $materials += $item['quantity'] * $item['unitCost'];
            $plantsNeeded += $item['quantity'] * $item['plantsPerUnit'];
            $assemblyMinutes += $item['quantity'] * $item['minutes'];
        }
        $labor = (int) round($assemblyMinutes * $hourlyRate / 60);
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

        return compact('items', 'hourlyRate', 'subtotal', 'materials', 'labor', 'assemblyMinutes', 'plantsNeeded', 'deliveryCost', 'deliveryCharged', 'adCost', 'otherCost', 'discount', 'total', 'costTotal', 'contribution') + ['contributionMargin' => $total > 0 ? $contribution / $total : 0];
    }

    private function integer(mixed $value, string $label, int $min = 0, int $max = 1000000000): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $min || (float) $value > $max || floor((float) $value) !== (float) $value) {
            throw new BusinessRule($label.': ingresa un número entero válido.', 400);
        }

        return (int) $value;
    }
}
