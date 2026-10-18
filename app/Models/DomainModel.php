<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $cutoff_at
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $reconciled_at
 * @property CarbonInterface|null $counted_at
 */
abstract class DomainModel extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime', 'is_active' => 'boolean', 'is_unplanned' => 'boolean', 'quantity' => 'decimal:4', 'approved_quantity' => 'decimal:4', 'fulfilled_quantity' => 'decimal:4', 'received_quantity' => 'decimal:4', 'returned_quantity' => 'decimal:4', 'system_quantity' => 'decimal:4', 'physical_quantity' => 'decimal:4', 'variance' => 'decimal:4', 'quantity_delta' => 'decimal:4', 'balance_after' => 'decimal:4', 'total' => 'decimal:2', 'estimated_total' => 'decimal:2', 'unit_price' => 'decimal:2', 'unit_cost' => 'decimal:2', 'standard_cost' => 'decimal:2', 'minimum_stock' => 'decimal:4', 'reorder_point' => 'decimal:4', 'authorized_amount' => 'decimal:2', 'amount' => 'decimal:2', 'commitment_delta' => 'decimal:2', 'expenditure_delta' => 'decimal:2', 'committed_amount' => 'decimal:2', 'spent_amount' => 'decimal:2', 'before_values' => 'array', 'after_values' => 'array', 'result' => 'array', 'value' => 'array', 'cutoff_at' => 'datetime', 'approved_at' => 'datetime', 'submitted_at' => 'datetime', 'reconciled_at' => 'datetime', 'counted_at' => 'datetime'];
    }
}
