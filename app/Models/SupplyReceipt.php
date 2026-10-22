<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(StockNestPolicy::class)]
class SupplyReceipt extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<ItemRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class, 'item_request_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<SupplyReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplyReceiptLine::class);
    }
}
