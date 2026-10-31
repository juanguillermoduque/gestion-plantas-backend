<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private int $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
        $this->seed(BusinessSeeder::class);
        Setting::findOrFail(1)->update(['hourly_rate' => 6000, 'timezone' => 'UTC']);
        $this->principal = Location::orderBy('id')->firstOrFail()->id;
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function serviceProduct(): void
    {
        $this->postJson('/api/products', $this->servicePayload())->assertCreated();
    }

    /** @return array<string, mixed> */
    private function servicePayload(): array
    {
        return ['id' => 'asesoria', 'name' => 'Asesoría', 'inventoryMode' => 'bom', 'components' => [], 'extraCost' => 1000, 'stockUnitsPerUnit' => 0, 'basePrice' => 5000, 'minutes' => 6, 'tiers' => [['minQty' => 1, 'price' => 5000]], 'active' => true];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function order(array $overrides = [], ?string $deliveredAt = '2026-11-15T12:00:00Z'): array
    {
        $order = $this->postJson('/api/orders', array_replace(['locationId' => $this->principal, 'customerName' => 'Cliente de prueba', 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => [['productId' => 'asesoria', 'quantity' => 1]]], $overrides))->assertCreated()->json('order');
        if ($deliveredAt !== null) {
            $this->patchJson('/api/orders/'.$order['id'], ['status' => 'delivered'])->assertOk();
            Order::findOrFail($order['id'])->update(['delivered_at' => CarbonImmutable::parse($deliveredAt)]);
        }

        return $order;
    }

    public function test_empty_month_returns_six_real_zero_months_without_sample_sales(): void
    {
        $this->getJson('/api/analytics?month=2026-11')->assertOk()
            ->assertJsonPath('locationId', $this->principal)->assertJsonPath('currency', 'COP')
            ->assertJsonCount(6, 'monthlyTrend')->assertJsonPath('monthlyTrend.0.month', '2026-06')
            ->assertJsonPath('monthlyTrend.5.month', '2026-11')->assertJsonPath('monthlyTrend.5.revenue', 0)
            ->assertJsonPath('monthlyTrend.5.operatingResult', 0)->assertJsonCount(0, 'topProducts')
            ->assertJsonCount(0, 'expenseCategories');
    }

    public function test_monthly_charts_use_delivered_snapshots_and_isolate_locations(): void
    {
        $this->serviceProduct();
        $this->order(['items' => [['productId' => 'asesoria', 'quantity' => 2]], 'paid' => 2100, 'adCost' => 500, 'deliveryCost' => 300, 'deliveryCharged' => 100, 'discount' => 500]);
        $this->order([], '2026-10-15T12:00:00Z');
        $this->order(['status' => 'quote', 'items' => [['productId' => 'asesoria', 'quantity' => 50]]], null);
        $cancelled = $this->order(['status' => 'quote'], null);
        $this->patchJson('/api/orders/'.$cancelled['id'], ['status' => 'cancelled'])->assertOk();
        $secondary = $this->postJson('/api/locations', ['name' => 'Sede secundaria'])->assertCreated()->json('location.id');
        $this->order(['locationId' => $secondary, 'items' => [['productId' => 'asesoria', 'quantity' => 10]]]);
        $this->postJson('/api/expenses', ['locationId' => $this->principal, 'date' => '2026-11-02', 'category' => 'Servicios', 'amount' => 700])->assertCreated();
        $this->postJson('/api/expenses', ['locationId' => $secondary, 'date' => '2026-11-02', 'category' => 'Servicios', 'amount' => 9000])->assertCreated();

        $report = $this->getJson('/api/analytics?locationId='.$this->principal.'&month=2026-11')->assertOk()
            ->assertJsonPath('monthlyTrend.5.revenue', 9600)->assertJsonPath('monthlyTrend.5.costTotal', 4000)
            ->assertJsonPath('monthlyTrend.5.contribution', 5600)->assertJsonPath('monthlyTrend.5.generalExpenses', 700)
            ->assertJsonPath('monthlyTrend.5.operatingResult', 4900)->assertJsonPath('monthlyTrend.5.orders', 1)
            ->assertJsonPath('monthlyTrend.4.revenue', 5000)->assertJsonPath('monthlyTrend.4.orders', 1)
            ->assertJsonPath('topProducts.0.quantity', 2)->assertJsonPath('topProducts.0.revenue', 10000)
            ->assertJsonPath('topProducts.0.materials', 2000)->assertJsonPath('topProducts.0.labor', 1200)
            ->assertJsonPath('topProducts.0.directContribution', 6800)->assertJsonPath('expenseCategories.0.amount', 700)->json();
        $this->assertSame(4700, array_sum(array_column($report['costBreakdown'], 'value')));
        $this->getJson('/api/analytics?locationId='.$secondary.'&month=2026-11')->assertOk()
            ->assertJsonPath('monthlyTrend.5.revenue', 50000)->assertJsonPath('monthlyTrend.5.generalExpenses', 9000);
    }

    public function test_cost_changes_do_not_rewrite_product_profit_history(): void
    {
        $this->serviceProduct();
        $this->order(['items' => [['productId' => 'asesoria', 'quantity' => 2]]]);
        $this->putJson('/api/products/asesoria', array_replace($this->servicePayload(), ['extraCost' => 8000]))->assertOk();
        $this->getJson('/api/analytics?month=2026-11')->assertOk()
            ->assertJsonPath('topProducts.0.materials', 2000)->assertJsonPath('topProducts.0.directContribution', 6800);
    }

    public function test_inventory_chart_values_each_supply_in_currency_and_uses_current_cost(): void
    {
        $supplyData = ['name' => 'Abono', 'category' => 'Cultivo', 'unit' => 'g', 'kind' => 'stock', 'unitCost' => 12, 'minStock' => 900, 'active' => true];
        $supply = $this->postJson('/api/supplies', $supplyData)->assertCreated()->json('supply');
        $this->postJson('/api/supplies', ['name' => 'Publicidad', 'unit' => 'servicio', 'kind' => 'expense', 'unitCost' => 100, 'minStock' => 0, 'active' => true])->assertCreated();
        $this->postJson('/api/inventory', ['locationId' => $this->principal, 'supplyId' => $supply['id'], 'type' => 'add', 'to' => 'ready', 'quantity' => 1000.5])->assertCreated();
        $this->postJson('/api/products', ['id' => 'arreglo', 'name' => 'Arreglo', 'inventoryMode' => 'bom', 'components' => [['supplyId' => $supply['id'], 'quantity' => 150.125]], 'extraCost' => 0, 'basePrice' => 5000, 'minutes' => 0, 'tiers' => [['minQty' => 1, 'price' => 5000]], 'active' => true])->assertCreated();
        $this->order(['items' => [['productId' => 'arreglo', 'quantity' => 1]]], null);
        $this->putJson('/api/supplies/'.$supply['id'], array_replace($supplyData, ['unitCost' => 24]))->assertOk();

        $report = $this->getJson('/api/analytics?month=2026-11')->assertOk()->json();
        $row = collect($report['inventoryValuation'])->firstWhere('supplyId', $supply['id']);
        $this->assertSame('g', $row['unit']);
        $this->assertEquals(1000.5, $row['total']);
        $this->assertEquals(150.125, $row['reserved']);
        $this->assertEquals(850.375, $row['available']);
        $this->assertTrue($row['belowMinimum']);
        $this->assertSame(24012, $row['estimatedValue']);
        $this->assertFalse(collect($report['inventoryValuation'])->contains('name', 'Publicidad'));
    }

    public function test_report_month_boundaries_follow_the_business_timezone(): void
    {
        $this->serviceProduct();
        $this->order([], '2026-10-31T18:00:00Z');
        Setting::findOrFail(1)->update(['timezone' => 'Asia/Tokyo']);
        $this->getJson('/api/analytics?month=2026-11')->assertOk()->assertJsonPath('monthlyTrend.5.revenue', 5000);
        $this->getJson('/api/analytics?month=2026-10')->assertOk()->assertJsonPath('monthlyTrend.5.revenue', 0);
    }

    public function test_reports_validate_filters_and_allow_active_operators(): void
    {
        $this->getJson('/api/analytics?month=2026-99')->assertStatus(400);
        $this->getJson('/api/analytics?locationId=999999999')->assertStatus(400);
        $this->actingAs(User::factory()->create(['role' => 'operator', 'active' => true]));
        $this->getJson('/api/analytics?month=2026-11')->assertOk();
    }

    public function test_anonymous_requests_cannot_read_reports(): void
    {
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/analytics?month=2026-11')->assertUnauthorized();
    }
}
