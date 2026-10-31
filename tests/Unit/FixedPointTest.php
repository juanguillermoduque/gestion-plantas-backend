<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRule;
use App\Services\FixedPoint;
use PHPUnit\Framework\TestCase;

class FixedPointTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_quantities_and_costs_keep_their_declared_precision(): void
    {
        $this->assertSame(125, FixedPoint::scaled(0.125, 3, 'Cantidad'));
        $this->assertSame(1, FixedPoint::scaled(0.0001, 4, 'Costo'));
        $this->assertSame(0.125, FixedPoint::number(125, 3));
        $this->assertSame(12, FixedPoint::number(120000, 4));
    }

    public function test_rounds_only_after_accumulating_fractional_costs(): void
    {
        $rawCost = FixedPoint::multiply(FixedPoint::scaled(3, 3, 'Cantidad'), FixedPoint::scaled(0.4, 4, 'Costo'));
        $this->assertSame(1, FixedPoint::roundRatio($rawCost, FixedPoint::COST_SCALE));
        $allocated = FixedPoint::allocate(['a' => 4000000, 'b' => 4000000]);
        $this->assertSame(1, array_sum($allocated));
    }

    public function test_rejects_more_than_three_decimals_for_a_quantity(): void
    {
        $this->expectException(BusinessRule::class);
        FixedPoint::scaled('0.0001', 3, 'Cantidad');
    }

    public function test_rejects_more_than_four_decimals_for_a_unit_cost(): void
    {
        $this->expectException(BusinessRule::class);
        FixedPoint::scaled('0.00001', 4, 'Costo');
    }

    public function test_detects_overflow_before_multiplication_can_turn_into_a_float(): void
    {
        $this->expectException(BusinessRule::class);
        FixedPoint::multiply(PHP_INT_MAX, 2);
    }

    public function test_detects_overflow_before_adding_scaled_costs(): void
    {
        $this->expectException(BusinessRule::class);
        FixedPoint::add(PHP_INT_MAX, 1);
    }
}
