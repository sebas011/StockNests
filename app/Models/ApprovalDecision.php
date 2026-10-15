<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(StockNestPolicy::class)]
class ApprovalDecision extends ImmutableRecord
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
