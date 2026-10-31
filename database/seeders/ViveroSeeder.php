<?php

namespace Database\Seeders;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ViveroSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            Setting::firstOrCreate(['id' => 1], ['business_name' => 'Mi vivero', 'hourly_rate' => 10000, 'monthly_fixed_costs' => 0, 'currency' => 'COP', 'timezone' => 'America/Bogota']);
            $balance = InventoryBalance::firstOrCreate(['id' => 1], ['unclassified' => 250, 'growing' => 0, 'mother' => 0, 'ready' => 0]);
            if ($balance->wasRecentlyCreated) {
                InventoryMovement::create(['type' => 'initial', 'to_bucket' => 'unclassified', 'quantity' => 250, 'note' => 'Inventario inicial informado: clasificar antes de confirmar ventas.']);
            }
            Product::firstOrCreate(['id' => 'individual'], [
                'name' => 'Suculenta individual', 'plants_per_unit' => 1, 'unit_cost' => 3300, 'base_price' => 6000, 'minutes' => 2,
                'components' => [['name' => 'Suculenta con cultivo y sustrato', 'quantity' => 1, 'unitCost' => 1000], ['name' => 'Matera de barro', 'quantity' => 1, 'unitCost' => 1800], ['name' => 'Decoración, tarjeta y empaque', 'quantity' => 1, 'unitCost' => 500]],
                'tiers' => [['minQty' => 1, 'price' => 6000], ['minQty' => 20, 'price' => 5500], ['minQty' => 40, 'price' => 5000]], 'active' => true,
            ]);
            Product::firstOrCreate(['id' => 'mini-jardin'], [
                'name' => 'Mini jardín de suculentas', 'plants_per_unit' => 3, 'unit_cost' => 11500, 'base_price' => 25000, 'minutes' => 2,
                'components' => [['name' => 'Suculenta con cultivo y sustrato', 'quantity' => 3, 'unitCost' => 1000], ['name' => 'Matera grande', 'quantity' => 1, 'unitCost' => 4500], ['name' => 'Decoración, tarjeta y empaque', 'quantity' => 1, 'unitCost' => 4000]],
                'tiers' => [['minQty' => 1, 'price' => 25000], ['minQty' => 20, 'price' => 23000], ['minQty' => 40, 'price' => 22000]], 'active' => true,
            ]);
        });
    }
}
