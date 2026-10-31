<?php

namespace App\Services;

use App\Exceptions\BusinessRule;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InventoryService $inventory, private readonly QuoteService $quotes, private readonly LocationService $locations) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): Order
    {
        return DB::transaction(function () use ($input, $user): Order {
            $location = $this->locations->resolve($input['locationId'] ?? null, true, true);
            $balance = $this->inventory->lock($location->id);
            $settings = Setting::query()->lockForUpdate()->findOrFail(1);
            $products = Product::all()->map(fn (Product $product): array => ApiView::product($product))->all();
            $quote = $this->quotes->calculate($input, $products, $settings->hourly_rate, ['currency' => $settings->currency, 'inventoryUnit' => $settings->inventory_unit, 'inventoryUnitSingular' => $settings->inventory_unit_singular]);
            $status = $input['status'] ?? 'quote';
            if ($status === 'confirmed' && $quote['stockUnitsNeeded'] > $balance->ready - $this->inventory->reserved($location->id)) {
                throw new BusinessRule('No hay suficientes existencias listas y libres en esta sede para confirmar el pedido. Puedes guardarlo como cotización.');
            }
            $paid = (int) ($input['paid'] ?? 0);
            if ($paid > $quote['total']) {
                throw new BusinessRule('El anticipo no puede superar el total del pedido.', 400);
            }
            $order = Order::create(['location_id' => $location->id, 'user_id' => $user->id, 'customer_name' => trim($input['customerName']), 'phone' => $input['phone'] ?? '', 'location' => $input['location'] ?? '', 'due_date' => $input['dueDate'], 'source' => $input['source'] ?? '', 'status' => $status, 'notes' => $input['notes'] ?? '', 'totals' => $quote, 'plants_needed' => $quote['stockUnitsNeeded']]);
            $order->update(['code' => 'PED-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT)]);
            if ($paid > 0) {
                Payment::create(['order_id' => $order->id, 'user_id' => $user->id, 'amount' => $paid, 'date' => now($settings->timezone)->toDateString(), 'note' => 'Anticipo inicial']);
            }

            return $order->load('payments');
        }, 3);
    }

    public function changeStatus(int $id, string $status, User $user): Order
    {
        return DB::transaction(function () use ($id, $status, $user): Order {
            $existing = Order::findOrFail($id);
            $location = $this->locations->resolve($existing->location_id, false, true);
            $balance = $this->inventory->lock($location->id);
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            if ($order->status === $status) {
                return $order->load('payments');
            }
            $transitions = ['quote' => ['confirmed', 'cancelled'], 'confirmed' => ['delivered', 'cancelled'], 'delivered' => [], 'cancelled' => []];
            if (! in_array($status, $transitions[$order->status], true)) {
                throw new BusinessRule('Ese cambio de estado no está permitido. Los pedidos entregados conservan su historial.');
            }
            if ($status === 'cancelled' && (int) $order->payments()->sum('amount') !== 0) {
                throw new BusinessRule('Registra primero la devolución de los pagos antes de cancelar el pedido.');
            }
            if ($status === 'confirmed' && $order->plants_needed > $balance->ready - $this->inventory->reserved($location->id, $order->id)) {
                throw new BusinessRule('No hay suficientes existencias listas y libres en la sede del pedido.');
            }
            if ($status === 'delivered') {
                if ($order->plants_needed > $balance->ready) {
                    throw new BusinessRule('El inventario de la sede no alcanza para registrar esta entrega.');
                }
                if ($order->plants_needed > 0) {
                    $balance->ready -= $order->plants_needed;
                    $balance->save();
                    InventoryMovement::create(['location_id' => $location->id, 'user_id' => $user->id, 'type' => 'delivery', 'from_bucket' => 'ready', 'quantity' => $order->plants_needed, 'note' => 'Entrega '.$order->code]);
                }
                $order->delivered_at = now();
            }
            $order->status = $status;
            $order->save();

            return $order->load('payments');
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function recordPayment(int $id, array $input, User $user): Payment
    {
        return DB::transaction(function () use ($id, $input, $user): Payment {
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            if ($order->status === 'cancelled') {
                throw new BusinessRule('Un pedido cancelado no admite nuevos pagos.');
            }
            $amount = (int) $input['amount'];
            $paid = (int) $order->payments()->sum('amount');
            if ($amount === 0 || $paid + $amount < 0 || $paid + $amount > $order->totals['total']) {
                throw new BusinessRule('El pago o devolución debe dejar el saldo recibido entre cero y el total del pedido.', 400);
            }

            return Payment::create(['order_id' => $id, 'user_id' => $user->id, 'amount' => $amount, 'date' => $input['date'], 'note' => $input['note'] ?? '']);
        }, 3);
    }
}
