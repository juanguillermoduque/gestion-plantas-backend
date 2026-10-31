<?php

namespace Database\Seeders;

use App\Models\InventoryBalance;
use App\Models\Location;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            Setting::firstOrCreate(['id' => 1], [
                'business_name' => 'Mi negocio',
                'business_type' => '',
                'city' => '',
                'country' => '',
                'delivery_coverage' => '',
                'inventory_unit' => 'Unidades',
                'inventory_unit_singular' => 'Unidad',
                'bucket_labels' => ['unclassified' => 'Sin clasificar', 'growing' => 'En preparación', 'mother' => 'Reserva interna', 'ready' => 'Disponible'],
                'hourly_rate' => 0,
                'monthly_fixed_costs' => 0,
                'currency' => 'COP',
                'locale' => 'es-CO',
                'timezone' => 'America/Bogota',
            ]);
            $location = Location::query()->orderBy('id')->first() ?? Location::create([
                'name' => 'Principal', 'address' => '', 'city' => '', 'country' => '', 'phone' => '', 'active' => true,
            ]);
            InventoryBalance::firstOrCreate(['location_id' => $location->id], ['unclassified' => 0, 'growing' => 0, 'mother' => 0, 'ready' => 0]);
        });
    }
}
