<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount
 */
#[UsePolicy(StockNestPolicy::class)]
class BudgetAdjustment extends DomainModel
{
    /** @return BelongsTo<BudgetAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(BudgetAllocation::class, 'budget_allocation_id');
    }
}
