<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

class ApiView
{
    /** @return array<string, mixed> */
    public static function product(Product $product): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'plantsPerUnit' => $product->plants_per_unit, 'unitCost' => $product->unit_cost, 'basePrice' => $product->base_price, 'minutes' => $product->minutes, 'components' => $product->components, 'tiers' => $product->tiers, 'active' => $product->active];
    }

    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'active' => $user->active];
    }

    /** @return array<string, mixed> */
    public static function settings(Setting $settings): array
    {
        return ['businessName' => $settings->business_name, 'hourlyRate' => $settings->hourly_rate, 'monthlyFixedCosts' => $settings->monthly_fixed_costs, 'currency' => $settings->currency, 'timezone' => $settings->timezone];
    }

    /** @return array<string, mixed> */
    public static function order(Order $order): array
    {
        $order->loadMissing('payments');
        $paid = (int) $order->payments->sum('amount');

        return ['id' => $order->id, 'code' => $order->code, 'customerName' => $order->customer_name, 'phone' => $order->phone, 'location' => $order->location, 'dueDate' => $order->due_date->format('Y-m-d'), 'source' => $order->source, 'status' => $order->status, 'notes' => $order->notes ?? '', 'items' => $order->totals['items'], 'totals' => $order->totals, 'paid' => $paid, 'balance' => $order->totals['total'] - $paid, 'payments' => $order->payments->map(fn ($payment): array => ['id' => $payment->id, 'amount' => $payment->amount, 'date' => $payment->date->format('Y-m-d'), 'note' => $payment->note, 'createdAt' => $payment->created_at->toISOString()])->all(), 'createdAt' => $order->created_at->toISOString(), 'deliveredAt' => $order->delivered_at?->toISOString()];
    }
}
