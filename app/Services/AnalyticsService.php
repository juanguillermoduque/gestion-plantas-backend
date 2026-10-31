<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Supply;
use Carbon\CarbonImmutable;

class AnalyticsService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** @return array<string, mixed> */
    public function report(int $locationId, string $month, Setting $settings): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $settings->timezone);
        $end = $start->addMonth();
        $historyStart = $start->subMonths(5);
        $trend = [];
        for ($cursor = $historyStart; $cursor->lessThan($end); $cursor = $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $trend[$key] = ['month' => $key, 'revenue' => 0, 'costTotal' => 0, 'contribution' => 0, 'generalExpenses' => 0, 'operatingResult' => 0, 'orders' => 0];
        }
        $orders = Order::query()->where('location_id', $locationId)->where('status', 'delivered')
            ->where('delivered_at', '>=', $historyStart->utc())->where('delivered_at', '<', $end->utc())
            ->orderBy('delivered_at')->orderBy('id')->get();
        $expenses = Expense::query()->where('location_id', $locationId)
            ->where('date', '>=', $historyStart->toDateString())->where('date', '<', $end->toDateString())->get();
        $products = [];
        $categories = [];
        $breakdown = [
            'materials' => ['key' => 'materials', 'name' => 'Insumos y costos de receta', 'value' => 0],
            'labor' => ['key' => 'labor', 'name' => 'Trabajo de preparación', 'value' => 0],
            'deliveryCost' => ['key' => 'deliveryCost', 'name' => 'Entrega', 'value' => 0],
            'adCost' => ['key' => 'adCost', 'name' => 'Publicidad por pedido', 'value' => 0],
            'otherCost' => ['key' => 'otherCost', 'name' => 'Otros costos por pedido', 'value' => 0],
            'generalExpenses' => ['key' => 'generalExpenses', 'name' => 'Gastos generales', 'value' => 0],
        ];
        foreach ($orders as $order) {
            $key = $order->delivered_at->setTimezone($settings->timezone)->format('Y-m');
            $totals = $order->totals;
            $trend[$key]['revenue'] += (int) $totals['total'];
            $trend[$key]['costTotal'] += (int) $totals['costTotal'];
            $trend[$key]['contribution'] += (int) $totals['contribution'];
            $trend[$key]['orders']++;
            if ($key !== $month) {
                continue;
            }
            foreach (['materials', 'labor', 'deliveryCost', 'adCost', 'otherCost'] as $costKey) {
                $breakdown[$costKey]['value'] += (int) ($totals[$costKey] ?? 0);
            }
            $labor = $this->allocateLabor($totals['items'], (int) ($totals['labor'] ?? 0));
            foreach ($totals['items'] as $index => $item) {
                $id = (string) $item['productId'];
                $products[$id] ??= ['productId' => $id, 'name' => '', 'quantity' => 0, 'revenue' => 0, 'materials' => 0, 'labor' => 0, 'directContribution' => 0];
                $quantity = (int) $item['quantity'];
                $products[$id]['name'] = $item['productName'] ?? $id;
                $products[$id]['quantity'] += $quantity;
                $products[$id]['revenue'] += $quantity * (int) $item['unitPrice'];
                $products[$id]['materials'] += isset($item['materialCost']) ? (int) $item['materialCost'] : (int) round($quantity * (float) $item['unitCost']);
                $products[$id]['labor'] += $labor[$index];
            }
        }
        foreach ($expenses as $expense) {
            $key = $expense->date->format('Y-m');
            $trend[$key]['generalExpenses'] += $expense->amount;
            if ($key === $month) {
                $category = $expense->category;
                $categories[$category] = ($categories[$category] ?? 0) + $expense->amount;
                $breakdown['generalExpenses']['value'] += $expense->amount;
            }
        }
        foreach ($trend as &$row) {
            $row['operatingResult'] = $row['contribution'] - $row['generalExpenses'];
        }
        unset($row);
        foreach ($products as &$row) {
            $row['directContribution'] = $row['revenue'] - $row['materials'] - $row['labor'];
        }
        unset($row);
        usort($products, fn (array $left, array $right): int => ($right['revenue'] <=> $left['revenue']) ?: strcmp($left['productId'], $right['productId']));
        arsort($categories);
        $expenseCategories = [];
        foreach ($categories as $category => $amount) {
            $expenseCategories[] = ['category' => $category, 'amount' => $amount];
        }

        return [
            'locationId' => $locationId, 'month' => $month, 'currency' => $settings->currency, 'moneyPrecision' => 0,
            'monthlyTrend' => array_values($trend), 'topProducts' => array_slice($products, 0, 10),
            'expenseCategories' => $expenseCategories, 'costBreakdown' => array_values($breakdown),
            'inventoryValuation' => $this->inventoryValuation($locationId),
        ];
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, int> */
    private function allocateLabor(array $items, int $budget): array
    {
        $weights = array_map(fn (array $item): float => (float) ($item['minutes'] ?? 0) * (int) $item['quantity'], $items);
        $weightTotal = array_sum($weights);
        $allocations = array_fill(0, count($items), 0);
        if ($weightTotal <= 0 || $budget <= 0) {
            return $allocations;
        }
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $exact = $budget * ($weight / $weightTotal);
            $allocations[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $allocations[$index];
        }
        arsort($remainders);
        $remaining = $budget - array_sum($allocations);
        foreach (array_keys($remainders) as $index) {
            if ($remaining <= 0) {
                break;
            }
            $allocations[$index]++;
            $remaining--;
        }

        return $allocations;
    }

    /** @return array<int, array<string, mixed>> */
    private function inventoryValuation(int $locationId): array
    {
        $supplies = Supply::query()->where('kind', 'stock')->get()->keyBy('id');
        $result = [];
        foreach ($this->inventory->balances($locationId) as $balance) {
            $supply = $supplies->get($balance['supplyId']);
            if ($supply === null) {
                continue;
            }
            $result[] = [
                'supplyId' => $supply->id, 'name' => $supply->name, 'unit' => $supply->unit,
                'total' => $balance['total'], 'reserved' => $balance['reserved'], 'available' => $balance['available'],
                'minStock' => (float) $supply->min_stock, 'unitCost' => (float) $supply->unit_cost,
                'estimatedValue' => (int) round($balance['total'] * (float) $supply->unit_cost),
                'belowMinimum' => $balance['belowMinimum'],
            ];
        }
        usort($result, fn (array $left, array $right): int => ($right['estimatedValue'] <=> $left['estimatedValue']) ?: ($left['supplyId'] <=> $right['supplyId']));

        return $result;
    }
}
