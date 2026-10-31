<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRule;
use App\Http\Requests\InventoryRequest;
use App\Http\Requests\LocationRequest;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\QuoteRequest;
use App\Http\Requests\SettingsRequest;
use App\Http\Requests\SupplyRequest;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCostChange;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\SupplyCostChange;
use App\Models\User;
use App\Services\ApiView;
use App\Services\InventoryService;
use App\Services\LocationService;
use App\Services\OrderService;
use App\Services\QuoteService;
use App\Services\RecipeService;
use App\Services\SupplyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApiController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly OrderService $orders, private readonly QuoteService $quotes, private readonly LocationService $locations, private readonly RecipeService $recipes, private readonly SupplyService $supplies) {}

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
        $request->validate(['month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'locationId' => ['sometimes', 'integer', 'min:1', 'exists:locations,id']]);
        $location = $this->locations->resolve($request->query('locationId'));
        $settings = Setting::findOrFail(1);
        $orders = Order::with('payments')->where('location_id', $location->id)->orderByDesc('id')->get();
        $userNames = User::query()->pluck('name', 'id');
        $month = $request->query('month', now($settings->timezone)->format('Y-m'));
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $settings->timezone);
        $end = $start->addMonth();
        $delivered = Order::query()->where('location_id', $location->id)->where('status', 'delivered')->where('delivered_at', '>=', $start->utc())->where('delivered_at', '<', $end->utc())->get();
        $generalExpenses = (int) Expense::query()->where('location_id', $location->id)->where('date', '>=', $start->toDateString())->where('date', '<', $end->toDateString())->sum('amount');
        $contribution = (int) $delivered->sum(fn (Order $order): int => $order->totals['contribution']);
        $outstanding = (int) $orders->whereIn('status', ['confirmed', 'delivered'])->sum(fn (Order $order): int => $order->totals['total'] - (int) $order->payments->sum('amount'));
        $stock = $this->inventory->snapshot($location->id);
        $balances = $this->inventory->balances($location->id);
        $supplyCatalog = Supply::orderBy('id')->get();
        $supplyById = $supplyCatalog->keyBy('id');

        return response()->json([
            'user' => ApiView::user($request->user()), 'settings' => ApiView::settings($settings) + ['currencyLocked' => $this->currencyLocked($settings)],
            'locationId' => $location->id,
            'locations' => Location::orderBy('id')->get()->map(fn (Location $branch): array => ApiView::location($branch))->all(),
            'products' => $this->recipes->catalog(),
            'supplies' => $supplyCatalog->map(fn (Supply $supply): array => ApiView::supply($supply))->all(),
            'balances' => $balances,
            'alerts' => array_values(array_filter($balances, fn (array $balance): bool => $balance['belowMinimum'])),
            'stock' => $stock,
            'movements' => InventoryMovement::where('location_id', $location->id)->orderByDesc('id')->get()->map(fn (InventoryMovement $movement): array => ['id' => $movement->id, 'locationId' => $movement->location_id, 'supplyId' => $movement->supply_id, 'supplyName' => $supplyById->get($movement->supply_id)?->name ?? 'Insumo anterior', 'unit' => $supplyById->get($movement->supply_id)?->unit ?? '', 'type' => $movement->type, 'from' => $movement->from_bucket, 'to' => $movement->to_bucket, 'quantity' => $movement->quantity, 'note' => $movement->note, 'createdAt' => $movement->created_at->toISOString(), 'userName' => $userNames[$movement->user_id] ?? 'Sistema'])->all(),
            'orders' => $orders->map(fn (Order $order): array => ApiView::order($order))->all(),
            'expenses' => Expense::where('location_id', $location->id)->orderByDesc('date')->orderByDesc('id')->get()->map(fn (Expense $expense): array => ['id' => $expense->id, 'locationId' => $expense->location_id, 'date' => $expense->date->format('Y-m-d'), 'category' => $expense->category, 'amount' => $expense->amount, 'note' => $expense->note])->all(),
            'users' => $request->user()->role === 'admin' ? User::orderBy('id')->get()->map(fn (User $user): array => ApiView::user($user))->all() : [],
            'costHistory' => ProductCostChange::orderByDesc('id')->limit(300)->get()->map(fn (ProductCostChange $change): array => ['id' => $change->id, 'productId' => $change->product_id, 'before' => $change->before, 'after' => $change->after, 'createdAt' => $change->created_at->toISOString(), 'userName' => $userNames[$change->user_id] ?? 'Sistema'])->all(),
            'supplyCostHistory' => SupplyCostChange::orderByDesc('id')->limit(300)->get()->map(fn (SupplyCostChange $change): array => ['id' => $change->id, 'supplyId' => $change->supply_id, 'before' => $change->before, 'after' => $change->after, 'createdAt' => $change->created_at->toISOString(), 'userName' => $userNames[$change->user_id] ?? 'Sistema'])->all(),
            'summary' => ['month' => $month, 'locationId' => $location->id, 'deliveredRevenue' => (int) $delivered->sum(fn (Order $order): int => $order->totals['total']), 'contribution' => $contribution, 'generalExpenses' => $generalExpenses, 'operatingResult' => $contribution - $generalExpenses, 'outstanding' => $outstanding, 'stockUnitsReserved' => $stock['reserved'], 'plantsReserved' => $stock['reserved'], 'cashReceived' => (int) Payment::query()->whereHas('order', fn ($query) => $query->where('location_id', $location->id))->where('date', '>=', $start->toDateString())->where('date', '<', $end->toDateString())->sum('amount')],
        ]);
    }

    public function quote(QuoteRequest $request): JsonResponse
    {
        $products = $this->recipes->catalog();
        $settings = Setting::findOrFail(1);
        $location = $this->locations->resolve($request->input('locationId'));
        $quote = $this->quotes->calculate($request->validated(), $products, $settings->hourly_rate, ['currency' => $settings->currency, 'inventoryUnit' => $settings->inventory_unit, 'inventoryUnitSingular' => $settings->inventory_unit_singular]);
        $availability = $this->inventory->availability($location->id, $quote['requirements']);
        $availability['canConfirm'] = $availability['canConfirm'] && $location->active;

        return response()->json(['quote' => $quote + $availability + ['locationId' => $location->id]]);
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
        $data = $request->validated();
        $id = $request->validated('id') ?? (string) Str::uuid();
        if (Product::whereKey($id)->exists()) {
            throw new BusinessRule('Ese código de producto ya existe.', 400);
        }
        $product = DB::transaction(function () use ($id, $data): Product {
            Setting::query()->lockForUpdate()->findOrFail(1);

            return Product::create(['id' => $id] + $this->productAttributes($data));
        });

        return response()->json(['ok' => true, 'product' => ApiView::product($product)], 201);
    }

    public function updateProduct(ProductRequest $request, Product $product): JsonResponse
    {
        $updated = DB::transaction(function () use ($product, $request): Product {
            Setting::query()->lockForUpdate()->findOrFail(1);
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = ApiView::product($locked);
            $locked->update($this->productAttributes($request->validated(), $locked));
            ProductCostChange::create(['product_id' => $locked->id, 'user_id' => $request->user()->id, 'before' => $before, 'after' => ApiView::product($locked)]);

            return $locked;
        });

        return response()->json(['ok' => true, 'product' => ApiView::product($updated)]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function productAttributes(array $data, ?Product $existing = null): array
    {
        $recipe = $this->recipes->attributes($data, $existing);
        $tiers = array_map(fn (array $tier): array => ['minQty' => (int) $tier['minQty'], 'price' => (int) $tier['price']], $data['tiers']);
        usort($tiers, fn (array $left, array $right): int => $left['minQty'] <=> $right['minQty']);
        if ($tiers[0]['minQty'] !== 1) {
            throw new BusinessRule('La primera escala debe comenzar en una unidad.', 400);
        }
        $tiers[0]['price'] = (int) $data['basePrice'];

        return ['name' => trim($data['name']), 'base_price' => (int) $data['basePrice'], 'minutes' => (float) $data['minutes'], 'tiers' => $tiers, 'active' => $data['active'] ?? true] + $recipe;
    }

    public function settings(SettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data): void {
            $settings = Setting::query()->lockForUpdate()->findOrFail(1);
            if (isset($data['currency']) && $data['currency'] !== $settings->currency && $this->currencyLocked($settings)) {
                throw new BusinessRule('La moneda ya está vinculada a productos, pedidos, gastos o valores de trabajo. Define la moneda al iniciar un negocio vacío; los importes existentes no se convierten automáticamente.');
            }
            $attributes = [];
            foreach (['businessName' => 'business_name', 'businessType' => 'business_type', 'city' => 'city', 'country' => 'country', 'deliveryCoverage' => 'delivery_coverage', 'inventoryUnit' => 'inventory_unit', 'inventoryUnitSingular' => 'inventory_unit_singular', 'currency' => 'currency', 'locale' => 'locale', 'timezone' => 'timezone', 'hourlyRate' => 'hourly_rate', 'monthlyFixedCosts' => 'monthly_fixed_costs'] as $input => $column) {
                if (array_key_exists($input, $data)) {
                    $attributes[$column] = $data[$input] ?? '';
                }
            }
            if (isset($data['bucketLabels'])) {
                $attributes['bucket_labels'] = $data['bucketLabels'];
            }
            $settings->update($attributes);
        }, 3);

        return response()->json(['ok' => true]);
    }

    public function createExpense(Request $request): JsonResponse
    {
        $data = $request->validate(['locationId' => ['sometimes', 'integer', 'min:1', 'exists:locations,id'], 'date' => ['required', 'date_format:Y-m-d'], 'category' => ['required', 'string', 'max:100'], 'amount' => ['required', 'integer', 'between:1,1000000000'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $data['note'] = $data['note'] ?? '';
        $expense = DB::transaction(function () use ($data, $request): Expense {
            $location = $this->locations->resolve($data['locationId'] ?? null, true, true);
            Setting::query()->lockForUpdate()->findOrFail(1);
            unset($data['locationId']);

            return Expense::create($data + ['location_id' => $location->id, 'user_id' => $request->user()->id]);
        }, 3);

        return response()->json(['ok' => true, 'id' => $expense->id], 201);
    }

    public function deleteExpense(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['ok' => true]);
    }

    private function currencyLocked(Setting $settings): bool
    {
        return $settings->hourly_rate > 0 || $settings->monthly_fixed_costs > 0 || Product::exists() || Order::exists() || Expense::exists() || $this->supplies->hasFinancialHistory();
    }

    public function createLocation(LocationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $location = DB::transaction(function () use ($data): Location {
            $location = Location::create(['name' => trim($data['name']), 'address' => $data['address'] ?? '', 'city' => $data['city'] ?? '', 'country' => $data['country'] ?? '', 'phone' => $data['phone'] ?? '', 'active' => $data['active'] ?? true]);
            $this->supplies->createBalancesForLocation($location->id);

            return $location;
        }, 3);

        return response()->json(['ok' => true, 'location' => ApiView::location($location)], 201);
    }

    public function updateLocation(LocationRequest $request, Location $location): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($location, $data): void {
            $branches = Location::query()->orderBy('id')->lockForUpdate()->get();
            $locked = $branches->firstWhere('id', $location->id);
            if ($locked->active && array_key_exists('active', $data) && ! $data['active'] && $branches->where('active', true)->count() <= 1) {
                throw new BusinessRule('Debe quedar al menos una sede activa.');
            }
            $attributes = [];
            foreach (['name', 'address', 'city', 'country', 'phone', 'active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field] ?? '';
                }
            }
            $locked->update($attributes);
        }, 3);

        return response()->json(['ok' => true, 'location' => ApiView::location($location->fresh())]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'max:200'], 'role' => ['required', Rule::in(['admin', 'operator'])]]);
        $user = User::create($data + ['active' => true]);

        return response()->json(['ok' => true, 'user' => ApiView::user($user)], 201);
    }

    public function createSupply(SupplyRequest $request): JsonResponse
    {
        return response()->json(['ok' => true, 'supply' => ApiView::supply($this->supplies->create($request->validated(), $request->user()))], 201);
    }

    public function updateSupply(SupplyRequest $request, Supply $supply): JsonResponse
    {
        return response()->json(['ok' => true, 'supply' => ApiView::supply($this->supplies->update($supply, $request->validated(), $request->user()))]);
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
