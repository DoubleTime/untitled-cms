<?php

namespace App\Policies;

use App\Models\UnysisBox;
use App\Models\User;

/**
 * UNYSIS Boxes are auto-registered by RPA-TOOL (docs/adr/0002); Team Members only
 * label, block or remove them — there is deliberately no `create`.
 */
class UnysisBoxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('unysis_boxes.view');
    }

    public function view(User $user, UnysisBox $unysisBox): bool
    {
        return $user->hasPermission('unysis_boxes.view');
    }

    public function update(User $user, UnysisBox $unysisBox): bool
    {
        return $user->hasPermission('unysis_boxes.edit');
    }

    public function delete(User $user, UnysisBox $unysisBox): bool
    {
        return $user->hasPermission('unysis_boxes.edit');
    }

    /** Blocking and unblocking is a separate, narrower permission. */
    public function block(User $user, UnysisBox $unysisBox): bool
    {
        return $user->hasPermission('unysis_boxes.block');
    }
}
