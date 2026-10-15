<?php

use App\Domain\Decimal;

test('decimal calculations preserve cents above JavaScript safe integer precision', function () {
    expect(Decimal::add('90071992547409.99', '0.01', 2))->toBe('90071992547410.00')
        ->and(Decimal::mul('1.005', '1'))->toBe('1.01')
        ->and(Decimal::sum([], 2))->toBe('0.00')
        ->and(Decimal::sum([], 4))->toBe('0.0000');
});
