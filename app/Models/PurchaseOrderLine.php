<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $received_quantity
 * @property numeric-string $unit_price
 * @property numeric-string $total
 */
#[UsePolicy(StockNestPolicy::class)]
class PurchaseOrderLine extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<ItemRequestLine, $this> */
    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(ItemRequestLine::class, 'item_request_line_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
