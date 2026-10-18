<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity_delta
 * @property numeric-string $balance_after
 */
#[UsePolicy(StockNestPolicy::class)]
class StockMovement extends ImmutableRecord
{
    /** @return BelongsTo<StockBalance, $this> */
    public function balance(): BelongsTo
    {
        return $this->belongsTo(StockBalance::class, 'stock_balance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
