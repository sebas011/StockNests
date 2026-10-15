<?php

namespace App\Domain;

final class Decimal
{
    public static function add(string|int $a, string|int $b, int $scale = 4): string
    {
        return bcadd((string) $a, (string) $b, $scale);
    }

    public static function sub(string|int $a, string|int $b, int $scale = 4): string
    {
        return bcsub((string) $a, (string) $b, $scale);
    }

    public static function mul(string|int $a, string|int $b, int $scale = 2): string
    {
        $raw = bcmul((string) $a, (string) $b, $scale + 1);

        return bcadd($raw, bccomp($raw, '0', $scale + 1) < 0 ? '-0.'.str_repeat('0', $scale).'5' : '0.'.str_repeat('0', $scale).'5', $scale);
    }

    public static function cmp(string|int $a, string|int $b, int $scale = 4): int
    {
        return bccomp((string) $a, (string) $b, $scale);
    }

    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = bcadd('0', '0', $scale);
        foreach ($values as $value) {
            $total = self::add($total, (string) $value, $scale);
        }

return $total;
    }
}
