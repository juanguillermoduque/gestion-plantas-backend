<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRule;
use App\Services\QuoteService;
use PHPUnit\Framework\TestCase;

class QuoteServiceTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function products(): array
    {
        return [
            ['id' => 'individual', 'name' => 'Individual', 'plantsPerUnit' => 1, 'unitCost' => 3300, 'basePrice' => 6000, 'minutes' => 2, 'active' => true, 'tiers' => [['minQty' => 1, 'price' => 6000], ['minQty' => 20, 'price' => 5500], ['minQty' => 40, 'price' => 5000]]],
            ['id' => 'mini-jardin', 'name' => 'Mini jardín', 'plantsPerUnit' => 3, 'unitCost' => 11500, 'basePrice' => 25000, 'minutes' => 2, 'active' => true, 'tiers' => [['minQty' => 1, 'price' => 25000], ['minQty' => 20, 'price' => 23000], ['minQty' => 40, 'price' => 22000]]],
        ];
    }

    public function test_calculates_quantity_tiers_and_three_plants_per_garden(): void
    {
        $service = new QuoteService;
        $individual = $service->calculate(['items' => [['productId' => 'individual', 'quantity' => 50]]], $this->products(), 10000);
        $this->assertSame(250000, $individual['total']);
        $this->assertSame(165000, $individual['materials']);
        $this->assertSame(16667, $individual['labor']);
        $this->assertSame(68333, $individual['contribution']);
        $this->assertSame(50, $individual['plantsNeeded']);
        $garden = $service->calculate(['items' => [['productId' => 'mini-jardin', 'quantity' => 50]]], $this->products(), 10000);
        $this->assertSame(1100000, $garden['total']);
        $this->assertSame(575000, $garden['materials']);
        $this->assertSame(508333, $garden['contribution']);
        $this->assertSame(150, $garden['plantsNeeded']);
    }

    public function test_rounds_labor_once_for_the_whole_order(): void
    {
        $quote = (new QuoteService)->calculate(['items' => [['productId' => 'individual', 'quantity' => 1], ['productId' => 'mini-jardin', 'quantity' => 1]]], $this->products(), 10000);
        $this->assertSame(667, $quote['labor']);
    }

    public function test_accounts_for_negotiated_prices_delivery_discount_and_order_costs(): void
    {
        $quote = (new QuoteService)->calculate(['items' => [['productId' => 'individual', 'quantity' => 40], ['productId' => 'mini-jardin', 'quantity' => 2, 'unitPrice' => 24000]], 'deliveryCharged' => 12000, 'deliveryCost' => 15000, 'adCost' => 10000, 'otherCost' => 5000, 'discount' => 8000], $this->products(), 10000);
        $this->assertSame(248000, $quote['subtotal']);
        $this->assertSame(252000, $quote['total']);
        $this->assertSame(155000, $quote['materials']);
        $this->assertSame(14000, $quote['labor']);
        $this->assertSame(199000, $quote['costTotal']);
        $this->assertSame(53000, $quote['contribution']);
        $this->assertSame(46, $quote['plantsNeeded']);
        $this->assertEqualsWithDelta(53000 / 252000, $quote['contributionMargin'], 0.000001);
    }

    public function test_rejects_duplicate_product_rows(): void
    {
        $this->expectException(BusinessRule::class);
        (new QuoteService)->calculate(['items' => [['productId' => 'individual', 'quantity' => 20], ['productId' => 'individual', 'quantity' => 20]]], $this->products());
    }

    public function test_rejects_fractional_quantities(): void
    {
        $this->expectException(BusinessRule::class);
        (new QuoteService)->calculate(['items' => [['productId' => 'individual', 'quantity' => 1.5]]], $this->products());
    }

    public function test_rejects_discount_larger_than_product_total(): void
    {
        $this->expectException(BusinessRule::class);
        (new QuoteService)->calculate(['items' => [['productId' => 'individual', 'quantity' => 1]], 'discount' => 6001], $this->products());
    }
}
