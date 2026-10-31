<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('operator');
            $table->boolean('active')->default(true);
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('business_name')->default('Mi vivero');
            $table->unsignedInteger('hourly_rate')->default(10000);
            $table->unsignedBigInteger('monthly_fixed_costs')->default(0);
            $table->string('currency', 3)->default('COP');
            $table->string('timezone')->default('America/Bogota');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('name', 100);
            $table->unsignedInteger('plants_per_unit');
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('base_price');
            $table->decimal('minutes', 8, 2);
            $table->json('components');
            $table->json('tiers');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->integer('unclassified')->default(0);
            $table->integer('growing')->default(0);
            $table->integer('mother')->default(0);
            $table->integer('ready')->default(0);
            $table->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('from_bucket', 20)->nullable();
            $table->string('to_bucket', 20)->nullable();
            $table->unsignedInteger('quantity');
            $table->string('note', 500)->default('');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 160);
            $table->string('phone', 40)->default('');
            $table->string('location', 200)->default('');
            $table->date('due_date');
            $table->string('source', 100)->default('');
            $table->string('status', 20)->default('quote')->index();
            $table->text('notes')->nullable();
            $table->json('totals');
            $table->unsignedInteger('plants_needed');
            $table->timestampTz('delivered_at')->nullable()->index();
            $table->timestampsTz();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->date('date')->index();
            $table->string('note', 500)->default('');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date')->index();
            $table->string('category', 100);
            $table->unsignedBigInteger('amount');
            $table->string('note', 500)->default('');
            $table->timestampsTz();
        });
        Schema::create('product_cost_changes', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 80);
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('before');
            $table->json('after');
            $table->timestampTz('created_at')->useCurrent();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_nonnegative CHECK (unclassified >= 0 AND growing >= 0 AND mother >= 0 AND ready >= 0)');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_valid_role CHECK (role IN ('admin', 'operator'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_valid_status CHECK (status IN ('quote', 'confirmed', 'delivered', 'cancelled'))");
            DB::statement('ALTER TABLE products ADD CONSTRAINT product_valid_values CHECK (plants_per_unit > 0 AND unit_cost >= 0 AND base_price > 0 AND minutes >= 0)');
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expense_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        foreach (['product_cost_changes', 'expenses', 'payments', 'orders', 'inventory_movements', 'inventory_balances', 'products', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_valid_role');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active']));
    }
};
