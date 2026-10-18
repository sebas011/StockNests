<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $returned_quantity
 */
#[UsePolicy(StockNestPolicy::class)]
class StockIssuanceLine extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<StockIssuance, $this> */
    public function issuance(): BelongsTo
    {
        return $this->belongsTo(StockIssuance::class, 'stock_issuance_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
