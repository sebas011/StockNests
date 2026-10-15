<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

#[UsePolicy(StockNestPolicy::class)]
class StockTransfer extends ImmutableRecord
{
    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function fund()
    {
        return $this->belongsTo(FundingSource::class, 'funding_source_id');
    }
}
