<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessSeeder;
use Database\Seeders\ViveroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($this->admin);
        $this->principal = Location::orderBy('id')->firstOrFail()->id;
    }

    private function secondLocation(): int
    {
        return $this->postJson('/api/locations', ['name' => 'Sede norte', 'city' => 'Medellín', 'country' => 'Colombia'])->assertCreated()->json('location.id');
    }

    private function ready(int $quantity, ?int $locationId = null): void
    {
        $this->postJson('/api/inventory', ['locationId' => $locationId ?? $this->principal, 'type' => 'transfer', 'from' => 'unclassified', 'to' => 'ready', 'quantity' => $quantity])->assertCreated();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function order(int $locationId, array $overrides = []): array
    {
        return $this->postJson('/api/orders', array_replace(['locationId' => $locationId, 'customerName' => 'Cliente de prueba', 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => [['productId' => 'individual', 'quantity' => 50]]], $overrides))->assertCreated()->json('order');
    }

    public function test_stock_reservations_and_movements_are_isolated_by_location(): void
    {
        $this->seed(ViveroSeeder::class);
        $secondary = $this->secondLocation();
        $this->ready(100);
        $this->postJson('/api/inventory', ['locationId' => $secondary, 'type' => 'add', 'to' => 'ready', 'quantity' => 12])->assertCreated();
        $order = $this->order($this->principal);
        $this->assertSame($this->principal, $order['locationId']);
        $this->postJson('/api/orders', ['locationId' => $secondary, 'customerName' => 'Sin stock suficiente', 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => [['productId' => 'individual', 'quantity' => 20]]])->assertStatus(409);
        $this->getJson('/api/state?locationId='.$this->principal)->assertOk()->assertJsonPath('locationId', $this->principal)->assertJsonPath('stock.ready', 100)->assertJsonPath('stock.reserved', 50)->assertJsonPath('stock.available', 50)->assertJsonCount(1, 'orders')->assertJsonCount(2, 'locations');
        $this->getJson('/api/state?locationId='.$secondary)->assertOk()->assertJsonPath('stock.total', 12)->assertJsonPath('stock.ready', 12)->assertJsonPath('stock.reserved', 0)->assertJsonCount(0, 'orders')->assertJsonCount(1, 'movements')->assertJsonCount(2, 'products')->assertJsonCount(2, 'locations');
    }

    public function test_deliveries_payments_expenses_and_summary_keep_the_original_location(): void
    {
        $this->seed(ViveroSeeder::class);
        $secondary = $this->secondLocation();
        $this->ready(100);
        $this->postJson('/api/inventory', ['locationId' => $secondary, 'type' => 'add', 'to' => 'ready', 'quantity' => 12])->assertCreated();
        $principalOrder = $this->order($this->principal, ['paid' => 10000]);
        $secondaryOrder = $this->order($secondary, ['paid' => 30000, 'items' => [['productId' => 'individual', 'quantity' => 10]]]);
        foreach ([$principalOrder, $secondaryOrder] as $order) {
            $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered', 'locationId' => $secondary])->assertOk();
        }
        foreach ([[$this->principal, 1000], [$secondary, 2000]] as [$locationId, $amount]) {
            $this->postJson('/api/expenses', ['locationId' => $locationId, 'date' => now('America/Bogota')->toDateString(), 'category' => 'Servicios', 'amount' => $amount])->assertCreated();
        }
        $this->getJson('/api/state?locationId='.$this->principal)->assertOk()->assertJsonPath('stock.ready', 50)->assertJsonPath('summary.deliveredRevenue', 250000)->assertJsonPath('summary.generalExpenses', 1000)->assertJsonPath('summary.cashReceived', 10000)->assertJsonPath('summary.outstanding', 240000)->assertJsonCount(1, 'expenses');
        $this->getJson('/api/state?locationId='.$secondary)->assertOk()->assertJsonPath('stock.ready', 2)->assertJsonPath('summary.deliveredRevenue', 60000)->assertJsonPath('summary.generalExpenses', 2000)->assertJsonPath('summary.cashReceived', 30000)->assertJsonPath('summary.outstanding', 30000)->assertJsonCount(1, 'expenses');
    }

    public function test_inactive_locations_remain_readable_and_can_complete_existing_orders(): void
    {
        $this->seed(ViveroSeeder::class);
        $secondary = $this->secondLocation();
        $this->ready(100);
        $order = $this->order($this->principal);
        $this->patchJson('/api/locations/'.$this->principal, ['active' => false])->assertOk();
        $this->getJson('/api/state?locationId='.$this->principal)->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('locations.0.active', false);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('locationId', $secondary);
        $this->postJson('/api/inventory', ['locationId' => $this->principal, 'type' => 'add', 'to' => 'ready', 'quantity' => 1])->assertStatus(409);
        $this->postJson('/api/expenses', ['locationId' => $this->principal, 'date' => '2026-10-31', 'category' => 'Prueba', 'amount' => 1000])->assertStatus(409);
        $this->postJson('/api/orders', ['locationId' => $this->principal, 'customerName' => 'Nuevo', 'dueDate' => '2026-12-15', 'items' => [['productId' => 'individual', 'quantity' => 1]]])->assertStatus(409);
        $this->postJson('/api/orders/'.$order['id'].'/payments', ['amount' => 10000, 'date' => '2026-10-31'])->assertCreated();
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->getJson('/api/state?locationId='.$this->principal)->assertJsonPath('stock.ready', 50)->assertJsonPath('orders.0.status', 'delivered');
        $this->patchJson('/api/locations/'.$secondary, ['active' => false])->assertStatus(409);
    }

    public function test_settings_support_generic_units_locale_and_currency_before_monetary_data(): void
    {
        $this->putJson('/api/settings', ['businessName' => 'Taller de cerámica', 'businessType' => 'Artesanías', 'city' => 'Medellín', 'country' => 'Colombia', 'deliveryCoverage' => 'Área urbana', 'inventoryUnit' => 'Piezas', 'inventoryUnitSingular' => 'Pieza', 'bucketLabels' => ['unclassified' => 'Sin clasificar', 'growing' => 'En elaboración', 'mother' => 'Reserva interna', 'ready' => 'Terminadas'], 'currency' => 'USD', 'locale' => 'en-US', 'timezone' => 'America/New_York', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0])->assertOk();
        $this->getJson('/api/state')->assertOk()->assertJsonPath('settings.businessName', 'Taller de cerámica')->assertJsonPath('settings.inventoryUnit', 'Piezas')->assertJsonPath('settings.bucketLabels.ready', 'Terminadas')->assertJsonPath('settings.currency', 'USD')->assertJsonPath('settings.locale', 'en-US')->assertJsonPath('settings.timezone', 'America/New_York')->assertJsonPath('settings.moneyPrecision', 0)->assertJsonPath('settings.currencyLocked', false);
    }

    public function test_rejects_invalid_locale_timezone_currency_and_fractional_money(): void
    {
        foreach ([['locale' => 'es-12'], ['locale' => 'not_a_locale'], ['timezone' => 'Mars/Olympus'], ['currency' => 'ZZZ'], ['moneyPrecision' => 2], ['monthlyFixedCosts' => 1.25]] as $invalid) {
            $this->putJson('/api/settings', array_replace(['businessName' => 'Negocio', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0], $invalid))->assertStatus(400);
        }
        foreach (['es-CO', 'en-US', 'pt-BR', 'zh-Hans-CN', 'es-419'] as $locale) {
            $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0, 'locale' => $locale])->assertOk();
        }
    }

    public function test_currency_cannot_relabel_existing_products_or_nonzero_global_costs(): void
    {
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 1000, 'monthlyFixedCosts' => 0])->assertOk();
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 1000, 'monthlyFixedCosts' => 0, 'currency' => 'USD'])->assertStatus(409);
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0])->assertOk();
        $this->postJson('/api/products', ['name' => 'Pieza', 'stockUnitsPerUnit' => 1, 'basePrice' => 5000, 'minutes' => 0, 'components' => [['name' => 'Material', 'quantity' => 1, 'unitCost' => 1000]], 'tiers' => [['minQty' => 1, 'price' => 5000]]])->assertCreated();
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0, 'currency' => 'USD'])->assertStatus(409);
        $this->getJson('/api/state')->assertJsonPath('settings.currency', 'COP')->assertJsonPath('settings.currencyLocked', true);
    }

    public function test_expenses_alone_lock_the_currency(): void
    {
        $this->postJson('/api/expenses', ['locationId' => $this->principal, 'date' => '2026-10-31', 'category' => 'Inicio', 'amount' => 1000])->assertCreated();
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0, 'currency' => 'USD'])->assertStatus(409);
    }

    public function test_operator_cannot_create_or_change_locations(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'active' => true]);
        $this->actingAs($operator);
        $this->postJson('/api/locations', ['name' => 'Otra sede'])->assertForbidden();
        $this->patchJson('/api/locations/'.$this->principal, ['name' => 'Cambio'])->assertForbidden();
        $this->getJson('/api/state')->assertOk()->assertJsonCount(1, 'locations');
    }

    public function test_orders_with_legacy_stock_keys_keep_cost_snapshots_when_serialized(): void
    {
        $this->seed(ViveroSeeder::class);
        $order = $this->order($this->principal, ['status' => 'quote']);
        $totals = $order['totals'];
        unset($totals['stockUnitsNeeded']);
        foreach ($totals['items'] as &$item) {
            unset($item['stockUnitsPerUnit']);
        }
        unset($item);
        Order::findOrFail($order['id'])->update(['totals' => $totals]);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('orders.0.totals.stockUnitsNeeded', 50)->assertJsonPath('orders.0.items.0.stockUnitsPerUnit', 1)->assertJsonPath('orders.0.totals.materials', 165000)->assertJsonPath('orders.0.totals.currency', 'COP');
    }

    public function test_services_can_be_delivered_without_consuming_inventory(): void
    {
        $product = $this->postJson('/api/products', ['name' => 'Asesoría', 'stockUnitsPerUnit' => 0, 'basePrice' => 25000, 'minutes' => 30, 'components' => [['name' => 'Materiales', 'quantity' => 1, 'unitCost' => 0]], 'tiers' => [['minQty' => 1, 'price' => 25000]]])->assertCreated()->assertJsonPath('product.stockUnitsPerUnit', 0)->json('product');
        $order = $this->order($this->principal, ['items' => [['productId' => $product['id'], 'quantity' => 2]]]);
        $this->assertSame(0, $order['totals']['stockUnitsNeeded']);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->getJson('/api/state')->assertOk()->assertJsonPath('stock.total', 0)->assertJsonPath('stock.reserved', 0)->assertJsonCount(0, 'movements')->assertJsonPath('summary.deliveredRevenue', 50000);
    }

    public function test_summary_month_uses_the_configured_timezone(): void
    {
        $this->seed(ViveroSeeder::class);
        $this->ready(10);
        $order = $this->order($this->principal, ['items' => [['productId' => 'individual', 'quantity' => 1]]]);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        Order::findOrFail($order['id'])->update(['delivered_at' => CarbonImmutable::parse('2026-10-31T18:00:00Z')]);
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 10000, 'monthlyFixedCosts' => 0, 'timezone' => 'Asia/Tokyo'])->assertOk();
        $this->getJson('/api/state?month=2026-11')->assertOk()->assertJsonPath('summary.deliveredRevenue', 6000);
        $this->getJson('/api/state?month=2026-10')->assertOk()->assertJsonPath('summary.deliveredRevenue', 0);
    }

    public function test_upgrade_repairs_a_legacy_balance_sequence_without_losing_stock(): void
    {
        $balance = InventoryBalance::where('location_id', $this->principal)->firstOrFail();
        DB::table('inventory_balances')->where('id', $balance->id)->update(['id' => 1, 'ready' => 7]);
        DB::statement("SELECT setval(pg_get_serial_sequence('inventory_balances', 'id'), 1, false)");
        $migration = require database_path('migrations/2026_10_31_141022_allow_products_without_stock_consumption.php');
        $migration->up();
        $secondary = $this->secondLocation();
        $this->assertDatabaseHas('inventory_balances', ['id' => 1, 'location_id' => $this->principal, 'ready' => 7]);
        $this->assertDatabaseHas('inventory_balances', ['id' => 2, 'location_id' => $secondary, 'ready' => 0]);
        $this->getJson('/api/state?locationId='.$secondary)->assertOk()->assertJsonPath('stock.total', 0);
    }
}
