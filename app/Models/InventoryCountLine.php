<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $system_quantity
 * @property numeric-string|null $physical_quantity
 * @property numeric-string|null $variance
 */
#[UsePolicy(StockNestPolicy::class)]
class InventoryCountLine extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<InventoryCount, $this> */
    public function count(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'inventory_count_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
