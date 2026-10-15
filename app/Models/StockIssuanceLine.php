<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(StockNestPolicy::class)]
class StockIssuanceLine extends DomainModel
{
    public $timestamps = false;

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(StockIssuance::class, 'stock_issuance_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
