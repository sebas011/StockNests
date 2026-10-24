<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $total
 * @property numeric-string $committed_amount
 * @property numeric-string $spent_amount
 * @property string|null $authorization_reference
 */
#[UsePolicy(StockNestPolicy::class)]
class PurchaseOrder extends DomainModel
{
    /** @return BelongsTo<ItemRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class, 'item_request_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<FundingSource, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(FundingSource::class, 'funding_source_id');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
