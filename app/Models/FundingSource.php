<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[UsePolicy(StockNestPolicy::class)]
class FundingSource extends DomainModel
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
