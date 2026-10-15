<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(StockNestPolicy::class)]
class ProjectionLine extends DomainModel
{
    public $timestamps = false;

    public function projection(): BelongsTo
    {
        return $this->belongsTo(AnnualProjection::class, 'annual_projection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
