<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property numeric-string $quantity */
#[UsePolicy(StockNestPolicy::class)]
class SupplyReceiptLine extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<SupplyReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(SupplyReceipt::class, 'supply_receipt_id');
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
