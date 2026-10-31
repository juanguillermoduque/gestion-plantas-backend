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
    public function __construct(private readonly InventoryService $inventory, private readonly QuoteService $quotes) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): Order
    {
        return DB::transaction(function () use ($input, $user): Order {
            $balance = $this->inventory->lock();
            $products = Product::all()->map(fn (Product $product): array => ApiView::product($product))->all();
            $quote = $this->quotes->calculate($input, $products, Setting::findOrFail(1)->hourly_rate);
            $status = $input['status'] ?? 'quote';
            if ($status === 'confirmed' && $quote['plantsNeeded'] > $balance->ready - $this->inventory->reserved()) {
                throw new BusinessRule('No hay suficientes plantas listas y libres para confirmar este pedido. Puedes guardarlo como cotización.');
            }
            $paid = (int) ($input['paid'] ?? 0);
            if ($paid > $quote['total']) {
                throw new BusinessRule('El anticipo no puede superar el total del pedido.', 400);
            }
            $order = Order::create(['user_id' => $user->id, 'customer_name' => trim($input['customerName']), 'phone' => $input['phone'] ?? '', 'location' => $input['location'] ?? '', 'due_date' => $input['dueDate'], 'source' => $input['source'] ?? '', 'status' => $status, 'notes' => $input['notes'] ?? '', 'totals' => $quote, 'plants_needed' => $quote['plantsNeeded']]);
            $order->update(['code' => 'PED-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT)]);
            if ($paid > 0) {
                Payment::create(['order_id' => $order->id, 'user_id' => $user->id, 'amount' => $paid, 'date' => now('America/Bogota')->toDateString(), 'note' => 'Anticipo inicial']);
            }

            return $order->load('payments');
        }, 3);
    }

    public function changeStatus(int $id, string $status, User $user): Order
    {
        return DB::transaction(function () use ($id, $status, $user): Order {
            $balance = $this->inventory->lock();
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
            if ($status === 'confirmed' && $order->plants_needed > $balance->ready - $this->inventory->reserved($order->id)) {
                throw new BusinessRule('No hay suficientes plantas listas y libres para confirmar este pedido.');
            }
            if ($status === 'delivered') {
                if ($order->plants_needed > $balance->ready) {
                    throw new BusinessRule('El inventario no alcanza para registrar esta entrega.');
                }
                $balance->ready -= $order->plants_needed;
                $balance->save();
                $order->delivered_at = now();
                InventoryMovement::create(['user_id' => $user->id, 'type' => 'delivery', 'from_bucket' => 'ready', 'quantity' => $order->plants_needed, 'note' => 'Entrega '.$order->code]);
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
