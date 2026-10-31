<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\ViveroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViveroApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ViveroSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($this->admin);
    }

    private function ready(int $quantity = 250): void
    {
        $this->postJson('/api/inventory', ['type' => 'transfer', 'from' => 'unclassified', 'to' => 'ready', 'quantity' => $quantity, 'note' => 'Clasificación de prueba'])->assertCreated();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function createOrder(array $overrides = []): array
    {
        return $this->postJson('/api/orders', array_replace(['customerName' => 'Cliente de prueba', 'location' => 'Pereira', 'dueDate' => '2026-12-15', 'source' => 'Recomendación', 'status' => 'confirmed', 'items' => [['productId' => 'individual', 'quantity' => 50]]], $overrides))->assertCreated()->json('order');
    }

    public function test_initial_inventory_is_unclassified_and_seeding_is_idempotent(): void
    {
        $this->seed(ViveroSeeder::class);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('stock.total', 250)->assertJsonPath('stock.unclassified', 250)->assertJsonPath('stock.ready', 0)->assertJsonPath('stock.available', 0)->assertJsonCount(2, 'products')->assertJsonCount(1, 'movements')->assertJsonCount(0, 'orders');
    }

    public function test_quotes_do_not_reserve_and_confirmation_requires_ready_plants(): void
    {
        $order = $this->createOrder(['status' => 'quote', 'items' => [['productId' => 'individual', 'quantity' => 40]]]);
        $this->getJson('/api/state')->assertJsonPath('stock.reserved', 0);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'confirmed'])->assertStatus(409);
        $this->ready(100);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'confirmed'])->assertOk();
        $this->getJson('/api/state')->assertJsonPath('stock.ready', 100)->assertJsonPath('stock.reserved', 40)->assertJsonPath('stock.available', 60);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'cancelled'])->assertOk();
        $this->getJson('/api/state')->assertJsonPath('stock.available', 100)->assertJsonPath('stock.reserved', 0);
    }

    public function test_reservations_prevent_overselling_and_delivery_consumes_once(): void
    {
        $this->ready();
        $order = $this->createOrder(['items' => [['productId' => 'individual', 'quantity' => 200]]]);
        $this->postJson('/api/orders', ['customerName' => 'Otro pedido', 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => [['productId' => 'mini-jardin', 'quantity' => 20]]])->assertStatus(409);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->getJson('/api/state')->assertJsonPath('stock.total', 50)->assertJsonPath('stock.ready', 50)->assertJsonPath('stock.reserved', 0);
    }

    public function test_reserved_plants_cannot_be_moved_or_marked_as_losses(): void
    {
        $this->ready(100);
        $this->createOrder(['items' => [['productId' => 'individual', 'quantity' => 80]]]);
        $this->postJson('/api/inventory', ['type' => 'loss', 'from' => 'ready', 'quantity' => 21])->assertStatus(409);
        $this->postJson('/api/inventory', ['type' => 'transfer', 'from' => 'ready', 'to' => 'mother', 'quantity' => 21])->assertStatus(409);
        $this->postJson('/api/inventory', ['type' => 'loss', 'from' => 'ready', 'quantity' => 20])->assertCreated();
        $this->getJson('/api/state')->assertJsonPath('stock.ready', 80)->assertJsonPath('stock.reserved', 80)->assertJsonPath('stock.available', 0)->assertJsonPath('stock.total', 230);
    }

    public function test_payments_and_refunds_have_limits_and_cancellation_requires_refund(): void
    {
        $this->ready();
        $order = $this->createOrder(['paid' => 50000]);
        $uri = '/api/orders/'.$order['id'];
        $this->postJson($uri.'/payments', ['amount' => 10000, 'date' => '2026-10-31'])->assertCreated();
        $this->postJson($uri.'/payments', ['amount' => 190001, 'date' => '2026-10-31'])->assertStatus(400);
        $this->postJson($uri.'/payments', ['amount' => -60001, 'date' => '2026-10-31'])->assertStatus(400);
        $this->patchJson($uri, ['status' => 'cancelled'])->assertStatus(409);
        $this->postJson($uri.'/payments', ['amount' => -60000, 'date' => '2026-10-31', 'note' => 'Devolución completa'])->assertCreated();
        $this->patchJson($uri, ['status' => 'cancelled'])->assertOk();
        $this->getJson('/api/state')->assertJsonPath('orders.0.paid', 0)->assertJsonPath('orders.0.status', 'cancelled')->assertJsonCount(3, 'orders.0.payments');
    }

    public function test_previous_order_costs_remain_unchanged_when_product_costs_change(): void
    {
        $order = $this->createOrder(['status' => 'quote']);
        $product = collect($this->getJson('/api/state')->json('products'))->firstWhere('id', 'individual');
        $product['components'][2]['unitCost'] = 700;
        $this->putJson('/api/products/individual', $product)->assertOk()->assertJsonPath('product.unitCost', 3500);
        $this->postJson('/api/quote', ['items' => [['productId' => 'individual', 'quantity' => 50]]])->assertOk()->assertJsonPath('quote.materials', 175000);
        $this->assertSame(165000, Order::findOrFail($order['id'])->totals['materials']);
        $this->getJson('/api/state')->assertJsonCount(1, 'costHistory');
    }

    public function test_summary_counts_delivered_orders_and_subtracts_general_expenses_once(): void
    {
        $this->ready();
        $order = $this->createOrder();
        $this->getJson('/api/state')->assertJsonPath('summary.deliveredRevenue', 0);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
        $this->postJson('/api/expenses', ['date' => now('America/Bogota')->toDateString(), 'category' => 'Servicios', 'amount' => 10000])->assertCreated();
        $this->postJson('/api/expenses', ['date' => '2000-01-01', 'category' => 'Histórico', 'amount' => 90000])->assertCreated();
        $this->putJson('/api/settings', ['businessName' => 'Mi vivero', 'hourlyRate' => 10000, 'monthlyFixedCosts' => 500000])->assertOk();
        $this->getJson('/api/state')->assertJsonPath('summary.deliveredRevenue', 250000)->assertJsonPath('summary.contribution', 68333)->assertJsonPath('summary.generalExpenses', 10000)->assertJsonPath('summary.operatingResult', 58333);
    }

    public function test_operator_has_access_to_operations_but_not_administration(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'active' => true]);
        $this->actingAs($operator);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('users', []);
        $this->postJson('/api/quote', ['items' => [['productId' => 'individual', 'quantity' => 10]]])->assertOk();
        $this->putJson('/api/settings', ['businessName' => 'Cambio', 'hourlyRate' => 1, 'monthlyFixedCosts' => 0])->assertForbidden();
        $this->postJson('/api/users', ['name' => 'Otra persona'])->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->actingAs($this->admin)->patchJson('/api/users/'.$operator->id, ['active' => false])->assertOk();
        $this->actingAs($operator->fresh())->getJson('/api/state')->assertUnauthorized();
    }

    public function test_last_active_administrator_cannot_be_disabled_or_demoted(): void
    {
        $this->patchJson('/api/users/'.$this->admin->id, ['active' => false])->assertStatus(409);
        $this->patchJson('/api/users/'.$this->admin->id, ['role' => 'operator'])->assertStatus(409);
    }

    public function test_api_requires_authentication_and_never_exposes_passwords(): void
    {
        $state = $this->getJson('/api/state')->assertOk()->json();
        $this->assertArrayNotHasKey('password', $state['user']);
        $this->assertArrayNotHasKey('password', $state['users'][0]);
        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/state')->assertUnauthorized();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_login_regenerates_session_and_logout_revokes_access(): void
    {
        auth()->guard('web')->logout();
        $user = User::factory()->create(['role' => 'admin', 'active' => true, 'email' => 'login@example.test', 'password' => 'Prueba-Login-Segura-2026!']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'contraseña-incorrecta'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => strtoupper($user->email), 'password' => 'Prueba-Login-Segura-2026!'])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_real_environment_requires_csrf_for_login_without_same_origin_header(): void
    {
        $this->app['env'] = 'production';
        $this->postJson('/api/login', ['email' => 'csrf@example.test', 'password' => 'Prueba-CSRF-Segura-2026!'])->assertStatus(419);
    }

    public function test_invalid_quote_input_is_rejected_before_persistence(): void
    {
        $this->postJson('/api/quote', ['items' => [['productId' => 'individual', 'quantity' => 1.5]]])->assertStatus(400);
        $this->postJson('/api/quote', ['items' => [['productId' => 'individual', 'quantity' => 20], ['productId' => 'individual', 'quantity' => 20]]])->assertStatus(400);
        $this->postJson('/api/orders', ['customerName' => 'Prueba', 'dueDate' => '2026-02-31', 'items' => [['productId' => 'individual', 'quantity' => 1]]])->assertStatus(400);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_creates_product_with_automatic_id_when_the_form_sends_empty_code(): void
    {
        $this->postJson('/api/products', ['id' => '', 'name' => 'Presentación de prueba', 'inventoryMode' => 'bom', 'extraCost' => 4000, 'basePrice' => 8000, 'minutes' => 2, 'components' => [], 'tiers' => [['minQty' => 1, 'price' => 8000]], 'active' => true])->assertCreated()->assertJsonPath('product.unitCost', 4000);
        $this->assertDatabaseHas('products', ['name' => 'Presentación de prueba', 'unit_cost' => 4000]);
    }

    public function test_invalid_bearer_token_is_unauthorized_instead_of_a_database_error(): void
    {
        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer token-de-prueba-invalido')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        auth()->guard('web')->logout();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/login', ['email' => 'limitado@example.test', 'password' => 'Credencial-Incorrecta-2026!'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'limitado@example.test', 'password' => 'Credencial-Incorrecta-2026!'])->assertStatus(429);
    }
}
