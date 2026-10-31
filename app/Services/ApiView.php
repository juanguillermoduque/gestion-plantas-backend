<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

class ApiView
{
    /** @return array<string, mixed> */
    public static function product(Product $product): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'stockUnitsPerUnit' => $product->plants_per_unit, 'plantsPerUnit' => $product->plants_per_unit, 'unitCost' => $product->unit_cost, 'basePrice' => $product->base_price, 'minutes' => $product->minutes, 'components' => $product->components, 'tiers' => $product->tiers, 'active' => $product->active];
    }

    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'active' => $user->active];
    }

    /** @return array<string, mixed> */
    public static function settings(Setting $settings): array
    {
        return ['businessName' => $settings->business_name, 'businessType' => $settings->business_type, 'city' => $settings->city, 'country' => $settings->country, 'deliveryCoverage' => $settings->delivery_coverage, 'inventoryUnit' => $settings->inventory_unit, 'inventoryUnitSingular' => $settings->inventory_unit_singular, 'bucketLabels' => $settings->bucket_labels, 'hourlyRate' => $settings->hourly_rate, 'monthlyFixedCosts' => $settings->monthly_fixed_costs, 'currency' => $settings->currency, 'locale' => $settings->locale, 'timezone' => $settings->timezone, 'moneyPrecision' => 0];
    }

    /** @return array<string, mixed> */
    public static function location(Location $location): array
    {
        return ['id' => $location->id, 'name' => $location->name, 'address' => $location->address, 'city' => $location->city, 'country' => $location->country, 'phone' => $location->phone, 'active' => $location->active];
    }

    /** @param array<string, mixed> $totals @return array<string, mixed> */
    public static function quote(array $totals): array
    {
        $totals['stockUnitsNeeded'] = $totals['stockUnitsNeeded'] ?? $totals['plantsNeeded'] ?? 0;
        $totals['plantsNeeded'] = $totals['plantsNeeded'] ?? $totals['stockUnitsNeeded'];
        $totals['moneyPrecision'] = 0;
        $totals['items'] = array_map(function (array $item): array {
            $item['stockUnitsPerUnit'] = $item['stockUnitsPerUnit'] ?? $item['plantsPerUnit'] ?? 0;
            $item['plantsPerUnit'] = $item['plantsPerUnit'] ?? $item['stockUnitsPerUnit'];

            return $item;
        }, $totals['items']);

        return $totals;
    }

    /** @return array<string, mixed> */
    public static function order(Order $order): array
    {
        $order->loadMissing('payments');
        $paid = (int) $order->payments->sum('amount');
        $totals = self::quote($order->totals);

        return ['id' => $order->id, 'code' => $order->code, 'locationId' => $order->location_id, 'customerName' => $order->customer_name, 'phone' => $order->phone, 'location' => $order->location, 'dueDate' => $order->due_date->format('Y-m-d'), 'source' => $order->source, 'status' => $order->status, 'notes' => $order->notes ?? '', 'items' => $totals['items'], 'totals' => $totals, 'paid' => $paid, 'balance' => $totals['total'] - $paid, 'payments' => $order->payments->map(fn (Payment $payment): array => ['id' => $payment->id, 'amount' => $payment->amount, 'date' => $payment->date->format('Y-m-d'), 'note' => $payment->note, 'createdAt' => $payment->created_at->toISOString()])->all(), 'createdAt' => $order->created_at->toISOString(), 'deliveredAt' => $order->delivered_at?->toISOString()];
    }
}
