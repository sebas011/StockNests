<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

#[UsePolicy(StockNestPolicy::class)]
class UnitOfMeasure extends DomainModel
{
    protected $table = 'units_of_measure';
}
