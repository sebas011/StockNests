<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $total
 */
#[UsePolicy(StockNestPolicy::class)]
class ProjectionLine extends DomainModel
{
    public $timestamps = false;

    /** @return BelongsTo<AnnualProjection, $this> */
    public function projection(): BelongsTo
    {
        return $this->belongsTo(AnnualProjection::class, 'annual_projection_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
