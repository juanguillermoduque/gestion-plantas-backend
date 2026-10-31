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
        Schema::create('supplies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 100)->default('');
            $table->string('unit', 40);
            $table->string('kind', 20)->default('stock');
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('min_stock', 18, 3)->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('is_legacy')->default(false);
            $table->timestamps();
        });
        Schema::create('supply_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supply_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            foreach (['unclassified', 'growing', 'mother', 'ready'] as $bucket) {
                $table->decimal($bucket, 18, 3)->default(0);
            }
            $table->timestamps();
            $table->unique(['location_id', 'supply_id']);
        });
        Schema::create('supply_cost_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supply_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('before');
            $table->json('after');
            $table->timestampTz('created_at')->useCurrent();
        });
        $settings = DB::table('settings')->first();
        $unit = $settings?->inventory_unit_singular ?: 'Unidad';
        $legacy = DB::table('supplies')->insertGetId(['name' => 'Inventario anterior', 'category' => 'Compatibilidad', 'unit' => $unit, 'kind' => 'stock', 'unit_cost' => 0, 'min_stock' => 0, 'active' => true, 'is_legacy' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_balances')->orderBy('id')->chunkById(200, function ($balances) use ($legacy): void {
            foreach ($balances as $balance) {
                DB::table('supply_balances')->insert(['id' => $balance->id, 'supply_id' => $legacy, 'location_id' => $balance->location_id, 'unclassified' => $balance->unclassified, 'growing' => $balance->growing, 'mother' => $balance->mother, 'ready' => $balance->ready, 'created_at' => $balance->created_at, 'updated_at' => $balance->updated_at]);
            }
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('supply_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->change();
        });
        DB::table('inventory_movements')->whereNull('supply_id')->update(['supply_id' => $legacy]);
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->foreignId('supply_id')->nullable(false)->change());
        Schema::table('products', function (Blueprint $table): void {
            $table->string('inventory_mode', 20)->default('legacy');
            $table->decimal('extra_cost', 18, 4)->default(0);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('inventory_mode', 20)->default('legacy');
            $table->decimal('plants_needed', 18, 3)->change();
        });
        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) use ($legacy, $unit): void {
            foreach ($orders as $order) {
                $totals = json_decode($order->totals, true, flags: JSON_THROW_ON_ERROR);
                $quantity = (float) $order->plants_needed;
                $quantity = floor($quantity) === $quantity ? (int) $quantity : $quantity;
                $totals['inventoryMode'] = 'legacy';
                $totals['requirements'] = $quantity > 0 ? [['supplyId' => $legacy, 'name' => 'Inventario anterior', 'unit' => $unit, 'quantity' => $quantity, 'unitCost' => 0, 'totalCost' => 0, 'kind' => 'stock', 'isLegacy' => true, 'inventoryOnly' => true]] : [];
                $totals['legacyMaterialCost'] = $totals['materials'];
                $totals['productExtras'] = 0;
                $totals['componentCost'] = 0;
                foreach ($totals['items'] as &$item) {
                    $item['materialCost'] = $item['materialCost'] ?? (int) round($item['quantity'] * $item['unitCost']);
                }
                unset($item);
                DB::table('orders')->where('id', $order->id)->update(['totals' => json_encode($totals, JSON_THROW_ON_ERROR)]);
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX supplies_one_legacy ON supplies (is_legacy) WHERE is_legacy = true');
            DB::statement("ALTER TABLE supplies ADD CONSTRAINT supplies_valid_values CHECK (kind IN ('stock', 'expense') AND unit_cost >= 0 AND min_stock >= 0)");
            DB::statement('ALTER TABLE supply_balances ADD CONSTRAINT supply_balances_nonnegative CHECK (unclassified >= 0 AND growing >= 0 AND mother >= 0 AND ready >= 0)');
            $this->repairBalanceSequence();
        }
    }

    public function repairBalanceSequence(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('supply_balances', 'id'), GREATEST(COALESCE(MAX(id), 0), 1), MAX(id) IS NOT NULL) FROM supply_balances");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('La migración de insumos conserva inventario e historial. Para volver a una versión anterior restaura una copia de seguridad verificada; no se elimina el catálogo automáticamente.');
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('inventory_mode');
            $table->unsignedInteger('plants_needed')->change();
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['inventory_mode', 'extra_cost']));
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supply_id');
            $table->unsignedInteger('quantity')->change();
        });
        Schema::dropIfExists('supply_cost_changes');
        Schema::dropIfExists('supply_balances');
        Schema::dropIfExists('supplies');
    }
};
