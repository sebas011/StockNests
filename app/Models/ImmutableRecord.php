<?php

namespace App\Models;

use LogicException;

abstract class ImmutableRecord extends DomainModel
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger records are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger records are immutable.'));
    }
}
