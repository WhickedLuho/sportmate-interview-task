<?php

namespace App\Policies;

use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SyncTargetPolicy
{
    /**
     * A user may only act on their own targets. Other people's targets answer
     * "not found" rather than "forbidden", so ids cannot be probed.
     */
    public function manage(User $user, SyncTarget $target): Response
    {
        return $user->id === $target->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
