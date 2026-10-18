<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $commitment_delta
 * @property numeric-string $expenditure_delta
 */
#[UsePolicy(StockNestPolicy::class)]
class FinancialEntry extends ImmutableRecord
{
    /** @return BelongsTo<BudgetAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(BudgetAllocation::class, 'budget_allocation_id');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
