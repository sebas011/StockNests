<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $approved_quantity
 * @property numeric-string $fulfilled_quantity
 * @property numeric-string $received_quantity
 * @property numeric-string $unit_price
 */
#[UsePolicy(StockNestPolicy::class)]
class ItemRequestLine extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<ItemRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class, 'item_request_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
