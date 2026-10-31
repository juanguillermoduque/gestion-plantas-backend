<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Supply;
use App\Models\SupplyBalance;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Database\Seeders\ViveroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyInventoryTest extends TestCase
{
    use RefreshDatabase;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->locationId = Location::orderBy('id')->firstOrFail()->id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function supply(string $name, int|float $cost, array $extra = []): array
    {
        return $this->postJson('/api/supplies', array_replace(['name' => $name, 'unit' => 'unidad', 'kind' => 'stock', 'unitCost' => $cost], $extra))->assertCreated()->json('supply');
    }

    /** @param array<int, array<string, mixed>> $components @param array<string, mixed> $extra @return array<string, mixed> */
    private function product(array $components, array $extra = []): array
    {
        return $this->postJson('/api/products', array_replace(['name' => 'Producto compuesto', 'inventoryMode' => 'bom', 'components' => $components, 'extraCost' => 0, 'basePrice' => 5000, 'minutes' => 0, 'tiers' => [['minQty' => 1, 'price' => 5000]], 'active' => true], $extra))->assertCreated()->json('product');
    }

    private function stock(int $supplyId, int|float $quantity, ?int $locationId = null): void
    {
        $this->postJson('/api/inventory', ['locationId' => $locationId ?? $this->locationId, 'supplyId' => $supplyId, 'type' => 'add', 'to' => 'ready', 'quantity' => $quantity])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function balance(int $supplyId, ?int $locationId = null): array
    {
        return collect($this->getJson('/api/state?locationId='.($locationId ?? $this->locationId))->assertOk()->json('balances'))->firstWhere('supplyId', $supplyId);
    }

    /** @param array<int, array<string, mixed>> $items @param array<string, mixed> $extra @return array<string, mixed> */
    private function order(array $items, array $extra = []): array
    {
        return $this->postJson('/api/orders', array_replace(['locationId' => $this->locationId, 'customerName' => 'Cliente de prueba', 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => $items], $extra))->assertCreated()->json('order');
    }

    public function test_costs_consumed_grams_and_delivers_only_the_required_quantity(): void
    {
        $supply = $this->supply('Abono', 12, ['unit' => 'g']);
        $product = $this->product([['supplyId' => $supply['id'], 'quantity' => 150]]);
        $this->assertSame(1800, $product['unitCost']);
        $items = [['productId' => $product['id'], 'quantity' => 2]];
        $this->postJson('/api/quote', ['locationId' => $this->locationId, 'items' => $items])->assertOk()->assertJsonPath('quote.materials', 3600)->assertJsonPath('quote.requirements.0.quantity', 300)->assertJsonPath('quote.canConfirm', false)->assertJsonPath('quote.shortages.0.shortage', 300);
        $this->stock($supply['id'], 1000);
        $order = $this->order($items);
        $this->assertSame(300, $this->balance($supply['id'])['reserved']);
        $this->assertSame(700, $this->balance($supply['id'])['available']);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->assertSame(700, $this->balance($supply['id'])['ready']);
        $this->assertSame(0, $this->balance($supply['id'])['reserved']);
    }

    public function test_requires_every_physical_component_but_never_reserves_expense_components(): void
    {
        $plant = $this->supply('Planta', 1000);
        $pot = $this->supply('Matera', 1800);
        $advertising = $this->supply('Presentación por unidad', 500, ['kind' => 'expense', 'unit' => 'servicio']);
        $product = $this->product([['supplyId' => $plant['id'], 'quantity' => 1], ['supplyId' => $pot['id'], 'quantity' => 1], ['supplyId' => $advertising['id'], 'quantity' => 1]]);
        $this->assertSame(3300, $product['unitCost']);
        $this->stock($plant['id'], 250);
        $this->stock($pot['id'], 30);
        $items = [['productId' => $product['id'], 'quantity' => 50]];
        $this->postJson('/api/quote', ['items' => $items])->assertOk()->assertJsonPath('quote.materials', 165000)->assertJsonPath('quote.canConfirm', false)->assertJsonPath('quote.shortages.0.supplyId', $pot['id'])->assertJsonPath('quote.shortages.0.shortage', 20);
        $this->stock($pot['id'], 20);
        $order = $this->order($items);
        $this->assertSame(50, $this->balance($plant['id'])['reserved']);
        $this->assertSame(50, $this->balance($pot['id'])['reserved']);
        $this->assertFalse(SupplyBalance::where('supply_id', $advertising['id'])->exists());
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->assertSame(200, $this->balance($plant['id'])['ready']);
        $this->assertSame(0, $this->balance($pot['id'])['ready']);
        $this->assertDatabaseCount('inventory_movements', 5);
    }

    public function test_aggregates_a_shared_supply_across_products_before_checking_availability(): void
    {
        $material = $this->supply('Material compartido', 10);
        $left = $this->product([['supplyId' => $material['id'], 'quantity' => 6]], ['name' => 'Producto A']);
        $right = $this->product([['supplyId' => $material['id'], 'quantity' => 6]], ['name' => 'Producto B']);
        $this->stock($material['id'], 10);
        $items = [['productId' => $left['id'], 'quantity' => 1], ['productId' => $right['id'], 'quantity' => 1]];
        $this->postJson('/api/quote', ['items' => $items])->assertOk()->assertJsonCount(1, 'quote.requirements')->assertJsonPath('quote.requirements.0.quantity', 12)->assertJsonPath('quote.shortages.0.shortage', 2);
        $this->postJson('/api/orders', ['customerName' => 'Prueba', 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => $items])->assertStatus(409);
        $this->assertSame(0, $this->balance($material['id'])['reserved']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_fractional_reservations_prevent_losses_and_delivery_leaves_no_rounding_residue(): void
    {
        $material = $this->supply('Mezcla', 12000, ['unit' => 'kg']);
        $product = $this->product([['supplyId' => $material['id'], 'quantity' => 0.333]]);
        $this->stock($material['id'], 1);
        $order = $this->order([['productId' => $product['id'], 'quantity' => 2]]);
        $this->assertSame(0.666, $this->balance($material['id'])['reserved']);
        $this->assertSame(0.334, $this->balance($material['id'])['available']);
        $this->postJson('/api/inventory', ['supplyId' => $material['id'], 'type' => 'loss', 'from' => 'ready', 'quantity' => 0.335])->assertStatus(409);
        $this->postJson('/api/inventory', ['supplyId' => $material['id'], 'type' => 'loss', 'from' => 'ready', 'quantity' => 0.334])->assertCreated();
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->assertSame(0, $this->balance($material['id'])['ready']);
        $this->assertSame(0, $this->balance($material['id'])['reserved']);
    }

    public function test_fractional_unit_cost_is_multiplied_before_rounding_currency_totals(): void
    {
        $cost = $this->supply('Costo por unidad', 0.4, ['kind' => 'expense', 'unit' => 'servicio']);
        $product = $this->product([['supplyId' => $cost['id'], 'quantity' => 1]]);
        $this->assertSame(0.4, $product['unitCost']);
        $this->postJson('/api/quote', ['items' => [['productId' => $product['id'], 'quantity' => 3]]])->assertOk()->assertJsonPath('quote.materials', 1)->assertJsonPath('quote.items.0.materialCost', 1)->assertJsonPath('quote.requirements.0.totalCost', 1)->assertJsonPath('quote.canConfirm', true);
        $this->postJson('/api/inventory', ['supplyId' => $cost['id'], 'type' => 'add', 'to' => 'ready', 'quantity' => 1])->assertStatus(400);
    }

    public function test_does_not_multiply_the_display_rounded_product_cost_for_large_orders(): void
    {
        $cost = $this->supply('Costo fraccionado', 0.4567, ['kind' => 'expense', 'unit' => 'servicio']);
        $product = $this->product([['supplyId' => $cost['id'], 'quantity' => 0.123]], ['basePrice' => 1, 'tiers' => [['minQty' => 1, 'price' => 1]]]);
        $this->assertSame(0.0562, $product['unitCost']);
        $this->postJson('/api/quote', ['items' => [['productId' => $product['id'], 'quantity' => 100000]]])->assertOk()->assertJsonPath('quote.materials', 5617)->assertJsonPath('quote.requirements.0.quantity', 12300);
    }

    public function test_material_subtotals_and_extra_costs_add_up_after_currency_rounding(): void
    {
        $a = $this->supply('Costo A', 0.4, ['kind' => 'expense', 'unit' => 'servicio']);
        $b = $this->supply('Costo B', 0.4, ['kind' => 'expense', 'unit' => 'servicio']);
        $product = $this->product([['supplyId' => $a['id'], 'quantity' => 1], ['supplyId' => $b['id'], 'quantity' => 1]], ['extraCost' => 0.4]);
        $quote = $this->postJson('/api/quote', ['items' => [['productId' => $product['id'], 'quantity' => 1]]])->assertOk()->json('quote');
        $this->assertSame(1, $quote['materials']);
        $this->assertSame($quote['materials'], array_sum(array_column($quote['requirements'], 'totalCost')) + $quote['productExtras'] + $quote['legacyMaterialCost']);
        $this->assertSame($quote['materials'], array_sum(array_column($quote['items'], 'materialCost')));
    }

    public function test_confirmed_orders_keep_recipe_and_cost_snapshot_after_catalog_changes(): void
    {
        $old = $this->supply('Matera anterior', 100);
        $replacement = $this->supply('Nueva presentación', 50);
        $product = $this->product([['supplyId' => $old['id'], 'quantity' => 1]]);
        $this->stock($old['id'], 10);
        $order = $this->order([['productId' => $product['id'], 'quantity' => 2]]);
        $this->patchJson('/api/supplies/'.$old['id'], ['unitCost' => 900, 'active' => false])->assertOk();
        $product['components'] = [['supplyId' => $replacement['id'], 'quantity' => 5]];
        $this->putJson('/api/products/'.$product['id'], $product)->assertOk()->assertJsonPath('product.unitCost', 250);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->assertSame(8, $this->balance($old['id'])['ready']);
        $this->assertSame(0, $this->balance($replacement['id'])['ready']);
        $this->assertSame(200, Order::findOrFail($order['id'])->totals['materials']);
        $this->assertSame(100, Order::findOrFail($order['id'])->totals['requirements'][0]['unitCost']);
    }

    public function test_cancellation_releases_all_components_without_consuming_stock(): void
    {
        $a = $this->supply('A', 10);
        $b = $this->supply('B', 20);
        $product = $this->product([['supplyId' => $a['id'], 'quantity' => 1], ['supplyId' => $b['id'], 'quantity' => 2]]);
        $this->stock($a['id'], 10);
        $this->stock($b['id'], 10);
        $order = $this->order([['productId' => $product['id'], 'quantity' => 3]]);
        $this->assertSame(3, $this->balance($a['id'])['reserved']);
        $this->assertSame(6, $this->balance($b['id'])['reserved']);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, $this->balance($a['id'])['available']);
        $this->assertSame(10, $this->balance($b['id'])['available']);
    }

    public function test_units_and_kinds_cannot_be_reinterpreted_after_stock_or_recipe_history(): void
    {
        $material = $this->supply('Abono', 12, ['unit' => 'g']);
        $this->stock($material['id'], 1000);
        $this->patchJson('/api/supplies/'.$material['id'], ['unit' => 'kg'])->assertStatus(409);
        $this->patchJson('/api/supplies/'.$material['id'], ['kind' => 'expense'])->assertStatus(409);
        $this->patchJson('/api/supplies/'.$material['id'], ['unitCost' => 15])->assertOk();
        $this->getJson('/api/state')->assertOk()->assertJsonCount(1, 'supplyCostHistory');
        $unused = $this->supply('Material de receta', 1);
        $this->product([['supplyId' => $unused['id'], 'quantity' => 1]]);
        $this->patchJson('/api/supplies/'.$unused['id'], ['unit' => 'otra unidad'])->assertStatus(409);
    }

    public function test_inactive_supply_blocks_new_entries_and_new_recipe_links_but_keeps_existing_stock(): void
    {
        $supply = $this->supply('Retirado', 10);
        $product = $this->product([['supplyId' => $supply['id'], 'quantity' => 1]]);
        $this->stock($supply['id'], 5);
        $this->patchJson('/api/supplies/'.$supply['id'], ['active' => false])->assertOk();
        $this->postJson('/api/inventory', ['supplyId' => $supply['id'], 'type' => 'add', 'to' => 'ready', 'quantity' => 1])->assertStatus(409);
        $this->postJson('/api/inventory', ['supplyId' => $supply['id'], 'type' => 'loss', 'from' => 'ready', 'quantity' => 1])->assertCreated();
        $this->postJson('/api/products', ['name' => 'Nueva receta', 'inventoryMode' => 'bom', 'components' => [['supplyId' => $supply['id'], 'quantity' => 1]], 'basePrice' => 5000, 'minutes' => 0, 'tiers' => [['minQty' => 1, 'price' => 5000]]])->assertStatus(400);
        $product['name'] = 'Misma receta';
        $this->putJson('/api/products/'.$product['id'], $product)->assertOk();
        $this->assertSame(4, $this->balance($supply['id'])['ready']);
    }

    public function test_balances_are_separate_by_location_and_never_add_different_units(): void
    {
        $supply = $this->supply('Abono', 12, ['unit' => 'g', 'minStock' => 20]);
        $second = $this->postJson('/api/locations', ['name' => 'Sucursal'])->assertCreated()->json('location.id');
        $this->stock($supply['id'], 1000);
        $this->stock($supply['id'], 7, $second);
        $this->assertSame(1000, $this->balance($supply['id'])['total']);
        $this->assertSame(7, $this->balance($supply['id'], $second)['total']);
        $this->getJson('/api/state?locationId='.$second)->assertOk()->assertJsonPath('stock.total', 0)->assertJsonCount(1, 'alerts')->assertJsonPath('alerts.0.supplyId', $supply['id']);
    }

    public function test_mixed_legacy_and_bom_orders_aggregate_physical_demand_without_recosting_legacy(): void
    {
        $this->seed(ViveroSeeder::class);
        $legacy = Supply::legacy();
        $this->patchJson('/api/supplies/'.$legacy->id, ['unitCost' => 1000])->assertOk();
        $this->postJson('/api/inventory', ['type' => 'transfer', 'from' => 'unclassified', 'to' => 'ready', 'quantity' => 100])->assertCreated();
        $product = $this->product([['supplyId' => $legacy->id, 'quantity' => 1]]);
        $order = $this->order([['productId' => 'individual', 'quantity' => 2], ['productId' => $product['id'], 'quantity' => 3]]);
        $requirement = $order['totals']['requirements'][0];
        $this->assertSame(5, $requirement['quantity']);
        $this->assertSame(2, $requirement['inventoryOnlyQuantity']);
        $this->assertSame(3, $requirement['costQuantity']);
        $this->assertSame(3000, $requirement['totalCost']);
        $this->assertSame(6600, $order['totals']['legacyMaterialCost']);
        $this->assertSame(9600, $order['totals']['materials']);
        $this->assertSame(5, $this->balance($legacy->id)['reserved']);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->assertSame(95, $this->balance($legacy->id)['ready']);
    }

    public function test_rejects_overprecision_and_overflow_and_ignores_client_supplied_component_cost(): void
    {
        $this->postJson('/api/supplies', ['name' => 'Inválido', 'unit' => 'g', 'kind' => 'stock', 'unitCost' => 0.00001])->assertStatus(400);
        $supply = $this->supply('Abono', 12, ['unit' => 'g']);
        $this->postJson('/api/inventory', ['supplyId' => $supply['id'], 'type' => 'add', 'to' => 'ready', 'quantity' => 0.0001])->assertStatus(400);
        $product = $this->product([['supplyId' => $supply['id'], 'quantity' => 150, 'unitCost' => 0]]);
        $this->assertSame(1800, $product['unitCost']);
        $expensive = $this->supply('Costo extremo', 1000000000);
        $this->postJson('/api/products', ['name' => 'Límite', 'inventoryMode' => 'bom', 'components' => [['supplyId' => $expensive['id'], 'quantity' => 1000000]], 'basePrice' => 5000, 'minutes' => 0, 'tiers' => [['minQty' => 1, 'price' => 5000]]])->assertStatus(400);
    }

    public function test_operator_cannot_edit_supply_catalog_and_nonzero_supply_cost_locks_currency(): void
    {
        $material = $this->supply('Material', 12);
        $this->putJson('/api/settings', ['businessName' => 'Negocio', 'hourlyRate' => 0, 'monthlyFixedCosts' => 0, 'currency' => 'USD'])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'operator', 'active' => true]));
        $this->postJson('/api/supplies', ['name' => 'Otro', 'unit' => 'g', 'kind' => 'stock', 'unitCost' => 10])->assertForbidden();
        $this->patchJson('/api/supplies/'.$material['id'], ['unitCost' => 20])->assertForbidden();
        $this->getJson('/api/state')->assertOk();
    }
}
