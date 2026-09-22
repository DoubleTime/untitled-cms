<?php

namespace App\Policies;

use App\Models\MachineModel;
use App\Models\User;

/**
 * Machine Brands and Machine Models share the single `machines.*` permission family.
 */
class MachineModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('machines.view');
    }

    public function view(User $user, MachineModel $machineModel): bool
    {
        return $user->hasPermission('machines.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('machines.create');
    }

    public function update(User $user, MachineModel $machineModel): bool
    {
        return $user->hasPermission('machines.edit');
    }

    public function delete(User $user, MachineModel $machineModel): bool
    {
        return $user->hasPermission('machines.delete');
    }
}
