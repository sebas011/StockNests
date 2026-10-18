<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/** @property array<string, mixed>|null $result */
#[UsePolicy(StockNestPolicy::class)]
class IdempotentCommand extends DomainModel
{
    public $timestamps = false;
}
