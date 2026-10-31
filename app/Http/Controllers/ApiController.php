<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRule;
use App\Http\Requests\InventoryRequest;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\QuoteRequest;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCostChange;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiView;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\QuoteService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApiController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly OrderService $orders, private readonly QuoteService $quotes) {}

    public function health(): JsonResponse
    {
        DB::select('SELECT 1');

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => ApiView::user($request->user())]);
    }

    public function state(Request $request): JsonResponse
    {
        $request->validate(['month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $settings = Setting::findOrFail(1);
        $orders = Order::with('payments')->orderByDesc('id')->get();
        $userNames = User::query()->pluck('name', 'id');
        $month = $request->query('month', now('America/Bogota')->format('Y-m'));
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'America/Bogota');
        $end = $start->addMonth();
        $delivered = Order::query()->where('status', 'delivered')->where('delivered_at', '>=', $start->utc())->where('delivered_at', '<', $end->utc())->get();
        $generalExpenses = (int) Expense::query()->where('date', '>=', $start->toDateString())->where('date', '<', $end->toDateString())->sum('amount');
        $contribution = (int) $delivered->sum(fn (Order $order): int => $order->totals['contribution']);
        $outstanding = (int) $orders->whereIn('status', ['confirmed', 'delivered'])->sum(fn (Order $order): int => $order->totals['total'] - (int) $order->payments->sum('amount'));
        $stock = $this->inventory->snapshot();

        return response()->json([
            'user' => ApiView::user($request->user()), 'settings' => ApiView::settings($settings),
            'products' => Product::orderBy('id')->get()->map(fn (Product $product): array => ApiView::product($product))->all(),
            'stock' => $stock,
            'movements' => InventoryMovement::orderByDesc('id')->get()->map(fn (InventoryMovement $movement): array => ['id' => $movement->id, 'type' => $movement->type, 'from' => $movement->from_bucket, 'to' => $movement->to_bucket, 'quantity' => $movement->quantity, 'note' => $movement->note, 'createdAt' => $movement->created_at->toISOString(), 'userName' => $userNames[$movement->user_id] ?? 'Sistema'])->all(),
            'orders' => $orders->map(fn (Order $order): array => ApiView::order($order))->all(),
            'expenses' => Expense::orderByDesc('date')->orderByDesc('id')->get()->map(fn (Expense $expense): array => ['id' => $expense->id, 'date' => $expense->date->format('Y-m-d'), 'category' => $expense->category, 'amount' => $expense->amount, 'note' => $expense->note])->all(),
            'users' => $request->user()->role === 'admin' ? User::orderBy('id')->get()->map(fn (User $user): array => ApiView::user($user))->all() : [],
            'costHistory' => ProductCostChange::orderByDesc('id')->limit(300)->get()->map(fn (ProductCostChange $change): array => ['id' => $change->id, 'productId' => $change->product_id, 'before' => $change->before, 'after' => $change->after, 'createdAt' => $change->created_at->toISOString(), 'userName' => $userNames[$change->user_id] ?? 'Sistema'])->all(),
            'summary' => ['month' => $month, 'deliveredRevenue' => (int) $delivered->sum(fn (Order $order): int => $order->totals['total']), 'contribution' => $contribution, 'generalExpenses' => $generalExpenses, 'operatingResult' => $contribution - $generalExpenses, 'outstanding' => $outstanding, 'plantsReserved' => $stock['reserved'], 'cashReceived' => (int) Payment::query()->where('date', '>=', $start->toDateString())->where('date', '<', $end->toDateString())->sum('amount')],
        ]);
    }

    public function quote(QuoteRequest $request): JsonResponse
    {
        $products = Product::all()->map(fn (Product $product): array => ApiView::product($product))->all();

        return response()->json(['quote' => $this->quotes->calculate($request->validated(), $products, Setting::findOrFail(1)->hourly_rate)]);
    }

    public function inventory(InventoryRequest $request): JsonResponse
    {
        $movement = $this->inventory->move($request->validated(), $request->user());

        return response()->json(['ok' => true, 'id' => $movement->id], 201);
    }

    public function createOrder(OrderRequest $request): JsonResponse
    {
        return response()->json(['ok' => true, 'order' => ApiView::order($this->orders->create($request->validated(), $request->user()))], 201);
    }

    public function status(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['quote', 'confirmed', 'delivered', 'cancelled'])]]);

        return response()->json(['ok' => true, 'order' => ApiView::order($this->orders->changeStatus($order, $data['status'], $request->user()))]);
    }

    public function payment(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'], 'date' => ['required', 'date_format:Y-m-d'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $payment = $this->orders->recordPayment($order, $data, $request->user());

        return response()->json(['ok' => true, 'id' => $payment->id], 201);
    }

    public function createProduct(ProductRequest $request): JsonResponse
    {
        $data = $this->productAttributes($request->validated());
        $id = $request->validated('id') ?? (string) Str::uuid();
        if (Product::whereKey($id)->exists()) {
            throw new BusinessRule('Ese código de producto ya existe.', 400);
        }
        $product = Product::create(['id' => $id] + $data);

        return response()->json(['ok' => true, 'product' => ApiView::product($product)], 201);
    }

    public function updateProduct(ProductRequest $request, Product $product): JsonResponse
    {
        $attributes = $this->productAttributes($request->validated());
        $updated = DB::transaction(function () use ($product, $attributes, $request): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = ApiView::product($locked);
            $locked->update($attributes);
            ProductCostChange::create(['product_id' => $locked->id, 'user_id' => $request->user()->id, 'before' => $before, 'after' => ApiView::product($locked)]);

            return $locked;
        });

        return response()->json(['ok' => true, 'product' => ApiView::product($updated)]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function productAttributes(array $data): array
    {
        $components = array_map(fn (array $component): array => ['name' => trim($component['name']), 'quantity' => (float) $component['quantity'], 'unitCost' => (int) $component['unitCost']], $data['components']);
        $unitCost = (int) round(array_sum(array_map(fn (array $component): float => $component['quantity'] * $component['unitCost'], $components)));
        if ($unitCost > 1000000000) {
            throw new BusinessRule('El costo total supera el límite permitido.', 400);
        }
        $tiers = array_map(fn (array $tier): array => ['minQty' => (int) $tier['minQty'], 'price' => (int) $tier['price']], $data['tiers']);
        usort($tiers, fn (array $left, array $right): int => $left['minQty'] <=> $right['minQty']);
        if ($tiers[0]['minQty'] !== 1) {
            throw new BusinessRule('La primera escala debe comenzar en una unidad.', 400);
        }
        $tiers[0]['price'] = (int) $data['basePrice'];

        return ['name' => trim($data['name']), 'plants_per_unit' => (int) $data['plantsPerUnit'], 'base_price' => (int) $data['basePrice'], 'unit_cost' => $unitCost, 'minutes' => (float) $data['minutes'], 'components' => $components, 'tiers' => $tiers, 'active' => $data['active'] ?? true];
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['businessName' => ['required', 'string', 'max:100'], 'hourlyRate' => ['required', 'integer', 'between:0,1000000'], 'monthlyFixedCosts' => ['required', 'integer', 'between:0,1000000000']]);
        Setting::findOrFail(1)->update(['business_name' => $data['businessName'], 'hourly_rate' => $data['hourlyRate'], 'monthly_fixed_costs' => $data['monthlyFixedCosts']]);

        return response()->json(['ok' => true]);
    }

    public function createExpense(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'category' => ['required', 'string', 'max:100'], 'amount' => ['required', 'integer', 'between:1,1000000000'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $data['note'] = $data['note'] ?? '';
        $expense = Expense::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['ok' => true, 'id' => $expense->id], 201);
    }

    public function deleteExpense(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['ok' => true]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'max:200'], 'role' => ['required', Rule::in(['admin', 'operator'])]]);
        $user = User::create($data + ['active' => true]);

        return response()->json(['ok' => true, 'user' => ApiView::user($user)], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:120'], 'password' => ['sometimes', 'required', 'string', 'min:12', 'max:200'], 'role' => ['sometimes', 'required', Rule::in(['admin', 'operator'])], 'active' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($data, $user): void {
            $administrators = User::query()->where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $willBeAdmin = ($data['role'] ?? $locked->role) === 'admin' && ($data['active'] ?? $locked->active);
            if ($locked->role === 'admin' && $locked->active && ! $willBeAdmin && $administrators->where('active', true)->count() <= 1) {
                throw new BusinessRule('Debe quedar al menos una persona administradora activa.');
            }
            $locked->update($data);
            if (array_key_exists('password', $data) || ($data['active'] ?? true) === false) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }
        }, 3);

        return response()->json(['ok' => true]);
    }
}
