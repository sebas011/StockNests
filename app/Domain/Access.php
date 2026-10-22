<?php

namespace App\Domain;

use App\Models\AuditLog;
use App\Models\FundingSource;
use App\Models\ItemRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class Access
{
    /** @return array<int, int> */
    public static function fundIds(User $user): array
    {
        return $user->fundingSources()->where('is_active', true)->pluck('funding_sources.id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function assert(User $user, string $permission, ?int $fund = null, ?int $department = null): void
    {
        if (! $user->is_active || ! $user->hasPermissionTo($permission) || ($fund !== null && ! in_array($fund, self::fundIds($user), true))) {
            throw new AuthorizationException('You do not have access to this operation or funding source.');
        }
        if ($department !== null && self::departmentLimited($user) && (int) $user->department_id !== $department) {
            throw new AuthorizationException('This record belongs to another department.');
        }
    }

    public static function departmentLimited(User $user): bool
    {
        if (OfficeWorkflow::enabled()) {
            return false;
        }

        // A newly configured role receives the narrower department scope by default.
        return ! $user->hasAnyRole(['Super Administrator', 'System Administrator', 'Budget Officer', 'Fund Approver', 'Procurement Officer', 'Inventory Custodian', 'Auditor']);
    }

    public static function ownRequestsOnly(User $user): bool
    {
        return $user->hasRole('Department Requester') && ! $user->hasRole('Department Head')
            && (OfficeWorkflow::enabled()
                ? ! $user->hasAnyPermission(['inventory.manage', 'procurement.manage', 'budgets.manage', 'users.manage', 'audit.view', 'requests.approve'])
                : self::departmentLimited($user));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, User $user, bool $department = false): Builder
    {
        if ($query->getModel() instanceof AuditLog && $user->can('audit.view')
            && FundingSource::whereNotIn('id', $user->fundingSources()->pluck('funding_sources.id'))->doesntExist()) {
            $query->where(fn ($q) => $q->whereIn('funding_source_id', self::fundIds($user))->orWhereNull('funding_source_id'));
        } else {
            $query->whereIn('funding_source_id', self::fundIds($user));
        }
        if ($department && self::departmentLimited($user)) {
            $query->where('department_id', $user->department_id);
        }
        if ($query->getModel() instanceof ItemRequest && self::ownRequestsOnly($user)) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }
}
