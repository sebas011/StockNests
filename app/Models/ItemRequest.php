<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $estimated_total
 * @property 'supply'|'cash' $channel
 */
#[UsePolicy(StockNestPolicy::class)]
class ItemRequest extends DomainModel
{
    /** @return BelongsTo<FundingSource, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(FundingSource::class, 'funding_source_id');
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ItemRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ItemRequestLine::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return HasMany<StockIssuance, $this> */
    public function issuances(): HasMany
    {
        return $this->hasMany(StockIssuance::class);
    }

    /** @return HasMany<SupplyReceipt, $this> */
    public function supplyReceipts(): HasMany
    {
        return $this->hasMany(SupplyReceipt::class);
    }
}
