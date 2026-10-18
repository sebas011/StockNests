<?php

namespace App\Domain;

final class Decimal
{
    /** @return numeric-string */
    public static function add(string|int $a, string|int $b, int $scale = 4): string
    {
        return bcadd(self::operand($a), self::operand($b), $scale);
    }

    /** @return numeric-string */
    public static function sub(string|int $a, string|int $b, int $scale = 4): string
    {
        return bcsub(self::operand($a), self::operand($b), $scale);
    }

    /** @return numeric-string */
    public static function mul(string|int $a, string|int $b, int $scale = 2): string
    {
        $raw = bcmul(self::operand($a), self::operand($b), $scale + 1);

        return bcadd($raw, self::operand(bccomp($raw, '0', $scale + 1) < 0 ? '-0.'.str_repeat('0', $scale).'5' : '0.'.str_repeat('0', $scale).'5'), $scale);
    }

    public static function cmp(string|int $a, string|int $b, int $scale = 4): int
    {
        return bccomp(self::operand($a), self::operand($b), $scale);
    }

    /**
     * @param  iterable<int|string>  $values
     * @return numeric-string
     */
    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = bcadd('0', '0', $scale);
        foreach ($values as $value) {
            $total = self::add($total, (string) $value, $scale);
        }

        return $total;
    }

    /** @return numeric-string */
    private static function operand(string|int $value): string
    {
        $operand = (string) $value;
        if (! is_numeric($operand)) {
            throw new \ValueError('Decimal operands must be numeric strings.');
        }

        return $operand;
    }
}
