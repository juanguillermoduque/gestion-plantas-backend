<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT product_valid_values');
            DB::statement('ALTER TABLE products ADD CONSTRAINT product_valid_values CHECK (plants_per_unit >= 0 AND unit_cost >= 0 AND base_price > 0 AND minutes >= 0)');
            DB::statement("SELECT setval(pg_get_serial_sequence('inventory_balances', 'id'), GREATEST(COALESCE(MAX(id), 0), 1), MAX(id) IS NOT NULL) FROM inventory_balances");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT product_valid_values');
            DB::statement('ALTER TABLE products ADD CONSTRAINT product_valid_values CHECK (plants_per_unit > 0 AND unit_cost >= 0 AND base_price > 0 AND minutes >= 0)');
        }
    }
};
