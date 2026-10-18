<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 */
#[UsePolicy(StockNestPolicy::class)]
class StockReservation extends DomainModel
{
    /** @return BelongsTo<StockBalance, $this> */
    public function balance(): BelongsTo
    {
        return $this->belongsTo(StockBalance::class, 'stock_balance_id');
    }

    /** @return BelongsTo<ItemRequestLine, $this> */
    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(ItemRequestLine::class, 'item_request_line_id');
    }
}
