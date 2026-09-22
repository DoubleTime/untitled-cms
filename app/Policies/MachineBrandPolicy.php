<?php

namespace App\Policies;

use App\Models\MachineBrand;
use App\Models\User;

/**
 * Machine Brands and Machine Models share the single `machines.*` permission family.
 */
class MachineBrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('machines.view');
    }

    public function view(User $user, MachineBrand $machineBrand): bool
    {
        return $user->hasPermission('machines.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('machines.create');
    }

    public function update(User $user, MachineBrand $machineBrand): bool
    {
        return $user->hasPermission('machines.edit');
    }

    public function delete(User $user, MachineBrand $machineBrand): bool
    {
        return $user->hasPermission('machines.delete');
    }
}
