<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\SupplyCostChange;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class ViveroSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BusinessSeeder::class);
        DB::transaction(function (): void {
            $settings = Setting::query()->lockForUpdate()->findOrFail(1);
            if (Product::whereKey('individual')->exists() && Product::whereKey('mini-jardin')->exists()) {
                return;
            }
            $hasStock = InventoryBalance::query()->where(fn ($query) => $query->where('unclassified', '>', 0)->orWhere('growing', '>', 0)->orWhere('mother', '>', 0)->orWhere('ready', '>', 0))->exists();
            if ($settings->currency !== 'COP' || Product::exists() || Order::exists() || Expense::exists() || InventoryMovement::exists() || Supply::where('is_legacy', false)->exists() || SupplyCostChange::exists() || $hasStock) {
                throw new LogicException('La carga opcional de suculentas requiere un negocio vacío y moneda COP. No se modificaron sus registros.');
            }
            $settings->update(['business_type' => 'Cultivo y venta de plantas', 'hourly_rate' => 10000, 'inventory_unit' => 'Plantas', 'inventory_unit_singular' => 'Planta', 'bucket_labels' => ['unclassified' => 'Sin clasificar', 'growing' => 'En crecimiento', 'mother' => 'Reproducción', 'ready' => 'Listas para vender']]);
            $location = Location::query()->where('active', true)->orderBy('id')->firstOrFail();
            $balance = InventoryBalance::query()->where('location_id', $location->id)->lockForUpdate()->firstOrFail();
            $balance->update(['unclassified' => 250]);
            InventoryMovement::create(['location_id' => $location->id, 'type' => 'initial', 'to_bucket' => 'unclassified', 'quantity' => 250, 'note' => 'Carga opcional: 250 plantas sin clasificar y dos productos de suculentas.']);
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
