<?php

namespace App\Support;

use App\Domain\Access;
use App\Models\AnnualProjection;
use App\Models\BudgetAdjustment;
use App\Models\GoodsReceipt;
use App\Models\InventoryCount;
use App\Models\ItemRequest;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockIssuance;
use App\Models\StockReservation;
use App\Models\User;

final class WorkflowVisibility
{
    /** @param array<string, mixed> $data */
    public static function canSee(User $user, array $data): bool
    {
        $fund = (int) ($data['funding_source_id'] ?? 0);
        if (! $user->is_active || ! in_array($fund, Access::fundIds($user), true)) {
            return false;
        }
        $type = $data['record_type'] ?? '';
        $allowed = [AnnualProjection::class, BudgetAdjustment::class, GoodsReceipt::class, InventoryCount::class, ItemRequest::class, PurchaseOrder::class, StockBalance::class, StockIssuance::class, StockReservation::class];
        $recordId = filter_var($data['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_string($type) || ! in_array($type, $allowed, true) || $recordId === false) {
            return false;
        }
        $record = $type::find($recordId);
        if (! $record) {
            return false;
        }
        $actualFund = match (true) {
            $record instanceof BudgetAdjustment => $record->allocation->funding_source_id,
            $record instanceof StockReservation => $record->balance->funding_source_id,
            default => $record->getAttribute('funding_source_id'),
        };
        if ((int) $actualFund !== $fund) {
            return false;
        }
        if ($record instanceof ItemRequest && Access::departmentLimited($user) && $user->hasRole('Department Requester') && ! $user->hasRole('Department Head') && (int) $record->created_by !== $user->id) {
            return false;
        }
        if (! Access::departmentLimited($user)) {
            return $user->can('dashboard.view');
        }
        $department = match (true) {
            $record instanceof GoodsReceipt => $record->order->department_id,
            $record instanceof StockIssuance => $record->request->department_id,
            default => $record->getAttribute('department_id'),
        };

        return $department !== null && (int) $department === (int) $user->department_id && $user->can('dashboard.view');
    }

    public static function destination(User $user, string $type): string
    {
        if ($user->hasAnyPermission(['requests.approve', 'budget.approve', 'fund.approve', 'counts.approve'])) {
            return '/app/approvals';
        }

        return match ($type) {
            AnnualProjection::class => '/app/projections',
            ItemRequest::class, StockIssuance::class => '/app/requests',
            PurchaseOrder::class, GoodsReceipt::class => $user->hasAnyPermission(['procurement.manage', 'inventory.manage', 'budgets.manage']) ? '/app/purchasing' : '/app/overview',
            InventoryCount::class => $user->can('inventory.manage') ? '/app/counts' : '/app/overview',
            StockBalance::class, StockReservation::class => $user->can('inventory.manage') ? '/app/inventory' : '/app/overview',
            BudgetAdjustment::class => $user->can('budgets.manage') ? '/app/funding' : '/app/overview',
            default => '/app/overview',
        };
    }
}
