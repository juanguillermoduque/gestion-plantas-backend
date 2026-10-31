<?php

namespace App\Services;

use App\Exceptions\BusinessRule;

class FixedPoint
{
    public const COST_SCALE = 10000000;

    public static function scaled(mixed $value, int $decimals, string $label, int $maxWhole = 1000000000): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new BusinessRule($label.': ingresa un número válido.', 400);
        }
        $value = trim((string) $value);
        if (! preg_match('/^([0-9]+)(?:\.([0-9]{1,'.$decimals.'}))?$/', $value, $matches)) {
            throw new BusinessRule($label.': usa un valor no negativo con máximo '.$decimals.' decimales.', 400);
        }
        $whole = ltrim($matches[1], '0') ?: '0';
        if (strlen($whole) > strlen((string) $maxWhole) || (strlen($whole) === strlen((string) $maxWhole) && strcmp($whole, (string) $maxWhole) > 0)) {
            throw new BusinessRule($label.': el valor supera el límite permitido.', 400);
        }
        $scale = 10 ** $decimals;
        $fraction = (int) str_pad($matches[2] ?? '', $decimals, '0');
        $result = (int) $whole * $scale + $fraction;
        if ($result > $maxWhole * $scale) {
            throw new BusinessRule($label.': el valor supera el límite permitido.', 400);
        }

        return $result;
    }

    public static function number(int $value, int $decimals): int|float
    {
        $scale = 10 ** $decimals;

        return $value % $scale === 0 ? intdiv($value, $scale) : $value / $scale;
    }

    public static function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right))) {
            throw new BusinessRule('La cantidad y el costo superan el límite seguro de cálculo. Reduce el tamaño del pedido o la unidad de medida.', 400);
        }

        return $left * $right;
    }

    public static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new BusinessRule('El total supera el límite seguro de cálculo.', 400);
        }

        return $left + $right;
    }

    public static function roundRatio(int $value, int $divisor): int
    {
        return intdiv($value, $divisor) + ($value % $divisor >= intdiv($divisor, 2) + $divisor % 2 ? 1 : 0);
    }

    /** @param array<int|string, int> $values @return array<int|string, int> */
    public static function allocate(array $values): array
    {
        $total = 0;
        $result = $remainders = [];
        foreach ($values as $key => $value) {
            $total = self::add($total, $value);
            $result[$key] = intdiv($value, self::COST_SCALE);
            $remainders[$key] = $value % self::COST_SCALE;
        }
        $remaining = self::roundRatio($total, self::COST_SCALE) - array_sum($result);
        arsort($remainders, SORT_NUMERIC);
        foreach ($remainders as $key => $remainder) {
            if ($remaining <= 0) {
                break;
            }
            $result[$key]++;
            $remaining--;
        }

        return $result;
    }
}
