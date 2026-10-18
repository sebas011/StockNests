<?php

namespace App\Policies;

use App\Domain\Access;
use App\Models\AnnualProjection;
use App\Models\ApprovalDecision;
use App\Models\AuditLog;
use App\Models\BudgetAdjustment;
use App\Models\BudgetAllocation;
use App\Models\FinancialEntry;
use App\Models\FundingSource;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\ItemRequest;
use App\Models\ItemRequestLine;
use App\Models\ProjectionLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockIssuance;
use App\Models\StockIssuanceLine;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class StockNestPolicy
{
    public function view(User $user, Model $record): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $parents = match (true) {
            $record instanceof BudgetAdjustment => [$record->allocation],
            $record instanceof FinancialEntry => [$record->allocation, $record->order],
            $record instanceof StockReservation => [$record->balance, $record->requestLine],
            $record instanceof StockMovement => [$record->balance],
            $record instanceof ProjectionLine => [$record->projection],
            $record instanceof ItemRequestLine => [$record->request],
            $record instanceof PurchaseOrderLine => [$record->order],
            $record instanceof GoodsReceiptLine => [$record->receipt],
            $record instanceof InventoryCountLine => [$record->count],
            $record instanceof StockIssuanceLine => [$record->issuance],
            $record instanceof GoodsReceipt => [$record->order],
            $record instanceof StockIssuance => [$record->request],
            $record instanceof ApprovalDecision => [$this->approvalSubject($record)],
            default => [],
        };
        foreach ($parents as $parent) {
            if (! $parent instanceof Model || ! $this->view($user, $parent)) {
                return false;
            }
        }
        if ($record instanceof AuditLog && (! $user->can('audit.view') || ($record->funding_source_id === null && FundingSource::whereNotIn('id', $user->fundingSources()->select('funding_sources.id'))->exists()))) {
            return false;
        }
        if ($record instanceof ItemRequest && Access::departmentLimited($user) && $user->hasRole('Department Requester') && ! $user->hasRole('Department Head') && (int) $record->created_by !== $user->id) {
            return false;
        }

        $fund = $record->getAttribute('funding_source_id');
        $department = $record->getAttribute('department_id');

        return (! $fund || in_array((int) $fund, Access::fundIds($user), true)) && (! $department || ! Access::departmentLimited($user) || (int) $department === (int) $user->department_id);
    }

    public function approve(User $user, Model $record): bool
    {
        return $this->view($user, $record) && (int) $record->getAttribute('created_by') !== $user->id;
    }

    private function approvalSubject(ApprovalDecision $record): ?Model
    {
        $class = $record->subject_type;
        if (! in_array($class, [AnnualProjection::class, BudgetAdjustment::class, BudgetAllocation::class, InventoryCount::class, ItemRequest::class, PurchaseOrder::class], true)) {
            return null;
        }

        return $class::find($record->subject_id);
    }
}
