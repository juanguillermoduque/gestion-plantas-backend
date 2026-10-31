<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ViveroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BusinessSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_installation_starts_empty_and_reseeding_preserves_configuration(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('settings', ['id' => 1, 'business_name' => 'Mi negocio', 'hourly_rate' => 0]);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(0, InventoryBalance::sum('ready'));
        $this->assertSame(0, InventoryBalance::sum('unclassified'));

        Setting::findOrFail(1)->update(['business_name' => 'Taller de cerámica', 'city' => 'Medellín']);
        $location = Location::firstOrFail();
        $location->update(['name' => 'Taller principal']);
        InventoryBalance::where('location_id', $location->id)->update(['ready' => 12]);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('settings', ['business_name' => 'Taller de cerámica', 'city' => 'Medellín']);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseHas('inventory_balances', ['location_id' => $location->id, 'ready' => 12]);
    }

    public function test_optional_succulent_setup_is_idempotent_and_never_adds_duplicate_stock(): void
    {
        $this->seed(ViveroSeeder::class);
        $location = Location::firstOrFail();
        InventoryBalance::where('location_id', $location->id)->update(['unclassified' => 230, 'ready' => 20]);
        $this->seed(ViveroSeeder::class);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_balances', ['location_id' => $location->id, 'unclassified' => 230, 'ready' => 20]);
        $this->assertSame(3300, Product::findOrFail('individual')->unit_cost);
    }

    public function test_optional_setup_refuses_to_mix_sample_stock_with_an_existing_business(): void
    {
        $this->seed(DatabaseSeeder::class);
        $location = Location::firstOrFail();
        InventoryBalance::where('location_id', $location->id)->update(['ready' => 7]);

        try {
            $this->seed(ViveroSeeder::class);
            $this->fail('La carga debe rechazar un inventario existente.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('negocio vacío', $exception->getMessage());
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertDatabaseHas('inventory_balances', ['location_id' => $location->id, 'ready' => 7]);
    }
}
