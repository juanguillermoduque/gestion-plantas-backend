<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_location_stock_and_historical_order_after_recipe_conversion(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_31_143622_add_supplies_and_recipe_inventory.php');
        $migration->down();

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $principal = (int) DB::table('locations')->orderBy('id')->value('id');
        $secondary = DB::table('locations')->insertGetId([
            'name' => 'Sede histórica norte', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'id' => 1, 'business_name' => 'Negocio existente', 'hourly_rate' => 12000,
            'monthly_fixed_costs' => 80000, 'currency' => 'COP', 'timezone' => 'America/Bogota',
            'inventory_unit' => 'Plantas', 'inventory_unit_singular' => 'Planta',
            'bucket_labels' => json_encode(['unclassified' => 'Por revisar', 'growing' => 'Cultivo', 'mother' => 'Reproducción', 'ready' => 'Venta'], JSON_THROW_ON_ERROR),
            'locale' => 'es-CO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $balances = [
            ['id' => 1, 'location_id' => $principal, 'unclassified' => 125, 'growing' => 5, 'mother' => 20, 'ready' => 100],
            ['id' => 42, 'location_id' => $secondary, 'unclassified' => 7, 'growing' => 3, 'mother' => 4, 'ready' => 20],
        ];
        foreach ($balances as $balance) {
            DB::table('inventory_balances')->insert($balance + ['created_at' => now(), 'updated_at' => now()]);
        }
        $movementId = DB::table('inventory_movements')->insertGetId([
            'location_id' => $principal, 'user_id' => $admin->id, 'type' => 'initial',
            'to_bucket' => 'unclassified', 'quantity' => 250, 'note' => 'Carga histórica comprobada',
            'created_at' => '2026-10-20 10:00:00+00',
        ]);
        $components = [['name' => 'Matera y decoración antiguas sin desglose verificable', 'quantity' => 1, 'unitCost' => 11500]];
        DB::table('products')->insert([
            'id' => 'combo-historico', 'name' => 'Producto histórico', 'plants_per_unit' => 3,
            'unit_cost' => 11500, 'base_price' => 22000, 'minutes' => 2,
            'components' => json_encode($components, JSON_THROW_ON_ERROR),
            'tiers' => json_encode([['minQty' => 1, 'price' => 22000]], JSON_THROW_ON_ERROR),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $originalTotals = [
            'items' => [[
                'productId' => 'combo-historico', 'productName' => 'Producto histórico', 'quantity' => 10,
                'unitPrice' => 22000, 'unitCost' => 11500, 'plantsPerUnit' => 3, 'minutes' => 2,
            ]],
            'hourlyRate' => 12000, 'subtotal' => 220000, 'materials' => 115000,
            'labor' => 4000, 'assemblyMinutes' => 20, 'plantsNeeded' => 30,
            'deliveryCost' => 10000, 'deliveryCharged' => 12000, 'adCost' => 5000,
            'otherCost' => 2000, 'discount' => 7000, 'total' => 225000,
            'costTotal' => 136000, 'contribution' => 89000, 'contributionMargin' => 89000 / 225000,
        ];
        $orderId = DB::table('orders')->insertGetId([
            'location_id' => $principal, 'code' => 'PED-HISTORICO-1', 'user_id' => $admin->id,
            'customer_name' => 'Cliente histórico de prueba', 'due_date' => '2026-11-15',
            'status' => 'confirmed', 'plants_needed' => 30,
            'totals' => json_encode($originalTotals, JSON_THROW_ON_ERROR),
            'created_at' => '2026-10-20 11:00:00+00', 'updated_at' => '2026-10-20 11:00:00+00',
        ]);
        DB::table('payments')->insert([
            'order_id' => $orderId, 'user_id' => $admin->id, 'amount' => 50000,
            'date' => '2026-10-20', 'note' => 'Anticipo histórico', 'created_at' => '2026-10-20 12:00:00+00',
        ]);

        $migration->up();

        $legacy = DB::table('supplies')->where('is_legacy', true)->sole();
        $this->assertDatabaseCount('supplies', 1);
        $this->assertDatabaseCount('supply_balances', 2);
        $this->assertSame('stock', $legacy->kind);
        $this->assertSame(0.0, (float) $legacy->unit_cost);
        foreach ($balances as $before) {
            $after = DB::table('supply_balances')->where('location_id', $before['location_id'])->where('supply_id', $legacy->id)->sole();
            foreach (['unclassified', 'growing', 'mother', 'ready'] as $bucket) {
                $this->assertSame((float) $before[$bucket], (float) $after->{$bucket});
            }
        }
        $this->assertDatabaseHas('inventory_movements', [
            'id' => $movementId, 'supply_id' => $legacy->id, 'location_id' => $principal,
            'type' => 'initial', 'quantity' => 250, 'note' => 'Carga histórica comprobada',
        ]);
        $this->assertSame($components, json_decode(DB::table('products')->where('id', 'combo-historico')->value('components'), true, flags: JSON_THROW_ON_ERROR));
        $upgradedTotals = json_decode(DB::table('orders')->where('id', $orderId)->value('totals'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($originalTotals as $key => $before) {
            if ($key !== 'items') {
                $this->assertSame($before, $upgradedTotals[$key]);
            }
        }
        foreach ($originalTotals['items'][0] as $key => $before) {
            $this->assertSame($before, $upgradedTotals['items'][0][$key]);
        }
        $this->assertCount(1, $upgradedTotals['requirements']);
        $this->assertSame((int) $legacy->id, $upgradedTotals['requirements'][0]['supplyId']);
        $this->assertSame(30, $upgradedTotals['requirements'][0]['quantity']);
        $this->assertTrue($upgradedTotals['requirements'][0]['inventoryOnly']);

        $this->actingAs($admin);
        $this->getJson('/api/state?locationId='.$principal)->assertOk()
            ->assertJsonPath('stock.ready', 100)->assertJsonPath('stock.reserved', 30)
            ->assertJsonPath('stock.available', 70);
        $newSupply = $this->postJson('/api/supplies', [
            'name' => 'Matera nueva', 'category' => 'Material físico', 'unit' => 'Unidad', 'kind' => 'stock',
            'unitCost' => 4500, 'minStock' => 0, 'active' => true,
        ])->assertCreated()->json('supply.id');
        $this->putJson('/api/products/combo-historico', [
            'name' => 'Producto convertido explícitamente', 'inventoryMode' => 'bom',
            'basePrice' => 22000, 'minutes' => 2, 'extraCost' => 0,
            'components' => [['supplyId' => $newSupply, 'quantity' => 1]],
            'tiers' => [['minQty' => 1, 'price' => 22000]], 'active' => true,
        ])->assertOk();

        $this->patchJson('/api/orders/'.$orderId, ['status' => 'delivered'])->assertOk()
            ->assertJsonPath('order.locationId', $principal)
            ->assertJsonPath('order.totals.materials', 115000)
            ->assertJsonPath('order.totals.total', 225000)
            ->assertJsonPath('order.totals.contribution', 89000)
            ->assertJsonPath('order.paid', 50000)
            ->assertJsonPath('order.balance', 175000);
        $this->assertSame(70.0, (float) DB::table('supply_balances')->where('supply_id', $legacy->id)->where('location_id', $principal)->value('ready'));
        $this->assertSame(20.0, (float) DB::table('supply_balances')->where('supply_id', $legacy->id)->where('location_id', $secondary)->value('ready'));
        $this->assertSame(0.0, (float) DB::table('supply_balances')->where('supply_id', $newSupply)->sum('ready'));
        $this->assertSame(100, (int) DB::table('inventory_balances')->where('location_id', $principal)->value('ready'));
        $this->assertDatabaseCount('supplies', 2);
        $this->getJson('/api/state?locationId='.$principal)->assertOk()
            ->assertJsonPath('stock.ready', 70)->assertJsonPath('stock.reserved', 0);
    }
}
