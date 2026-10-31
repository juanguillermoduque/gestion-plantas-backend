<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('address', 240)->default('');
            $table->string('city', 120)->default('');
            $table->string('country', 120)->default('');
            $table->string('phone', 40)->default('');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $principal = DB::table('locations')->insertGetId(['name' => 'Principal', 'address' => '', 'city' => '', 'country' => '', 'phone' => '', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['inventory_balances', 'inventory_movements', 'orders', 'expenses'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            });
            DB::table($name)->whereNull('location_id')->update(['location_id' => $principal]);
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('location_id')->nullable(false)->change();
            });
        }
        Schema::table('inventory_balances', fn (Blueprint $table) => $table->unique('location_id'));
        Schema::table('orders', fn (Blueprint $table) => $table->index(['location_id', 'status']));
        Schema::table('expenses', fn (Blueprint $table) => $table->index(['location_id', 'date']));
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->index(['location_id', 'id']));
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('business_type', 120)->default('');
            $table->string('city', 120)->default('');
            $table->string('country', 120)->default('');
            $table->string('delivery_coverage', 500)->default('');
            $table->string('inventory_unit', 60)->default('Unidades');
            $table->string('inventory_unit_singular', 60)->default('Unidad');
            $table->json('bucket_labels')->nullable();
            $table->string('locale', 35)->default('es-CO');
        });
        DB::table('settings')->whereNull('bucket_labels')->update(['bucket_labels' => json_encode(['unclassified' => 'Sin clasificar', 'growing' => 'En preparación', 'mother' => 'Reserva interna', 'ready' => 'Disponible'], JSON_THROW_ON_ERROR)]);
        $settings = DB::table('settings')->first();
        if ($settings !== null) {
            DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) use ($settings): void {
                foreach ($orders as $order) {
                    $totals = json_decode($order->totals, true, flags: JSON_THROW_ON_ERROR);
                    $totals['stockUnitsNeeded'] = $totals['stockUnitsNeeded'] ?? $totals['plantsNeeded'] ?? $order->plants_needed;
                    $totals['currency'] = $totals['currency'] ?? $settings->currency;
                    $totals['moneyPrecision'] = 0;
                    $totals['inventoryUnit'] = $totals['inventoryUnit'] ?? $settings->inventory_unit;
                    DB::table('orders')->where('id', $order->id)->update(['totals' => json_encode($totals, JSON_THROW_ON_ERROR)]);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn(['business_type', 'city', 'country', 'delivery_coverage', 'inventory_unit', 'inventory_unit_singular', 'bucket_labels', 'locale']));
        foreach (['expenses', 'orders', 'inventory_movements', 'inventory_balances'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('location_id'));
        }
        Schema::dropIfExists('locations');
    }
};
