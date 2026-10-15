<?php

namespace App\Policies;

use App\Domain\Access;
use App\Models\ItemRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class StockNestPolicy
{
    public function view(User $user, Model $record): bool
    {
        if ($record instanceof ItemRequest && Access::departmentLimited($user) && $user->hasRole('Department Requester') && ! $user->hasRole('Department Head') && (int) $record->created_by !== $user->id) {
            return false;
        }

        return $user->is_active && (! $record->funding_source_id || in_array((int) $record->funding_source_id, Access::fundIds($user), true)) && (! $record->department_id || ! Access::departmentLimited($user) || (int) $record->department_id === (int) $user->department_id);
    }

    public function approve(User $user, Model $record): bool
    {
        return $this->view($user, $record) && (int) $record->created_by !== $user->id;
    }
}
