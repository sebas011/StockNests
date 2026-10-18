<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $authorized_amount
 */
#[UsePolicy(StockNestPolicy::class)]
class BudgetAllocation extends DomainModel
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

    /** @return HasMany<FinancialEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }
}
