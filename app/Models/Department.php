<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(StockNestPolicy::class)]
class Department extends DomainModel
{
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
