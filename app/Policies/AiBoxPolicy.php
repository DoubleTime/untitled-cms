<?php

namespace App\Policies;

use App\Models\AiBox;
use App\Models\User;

/**
 * AI Boxes are auto-registered by RPA-TOOL (docs/adr/0002); Team Members only
 * label, block or remove them — there is deliberately no `create`.
 */
class AiBoxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ai_boxes.view');
    }

    public function view(User $user, AiBox $aiBox): bool
    {
        return $user->hasPermission('ai_boxes.view');
    }

    public function update(User $user, AiBox $aiBox): bool
    {
        return $user->hasPermission('ai_boxes.edit');
    }

    public function delete(User $user, AiBox $aiBox): bool
    {
        return $user->hasPermission('ai_boxes.edit');
    }

    /** Blocking and unblocking is a separate, narrower permission. */
    public function block(User $user, AiBox $aiBox): bool
    {
        return $user->hasPermission('ai_boxes.block');
    }
}
