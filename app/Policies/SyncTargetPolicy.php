<?php

namespace App\Policies;

use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SyncTargetPolicy
{
    /**
     * Allow management of owned targets and hide targets owned by another user.
     *
     * @param  User  $user  Authenticated user requesting the action.
     * @param  SyncTarget  $target  Target whose ownership is checked.
     *
     * @return Response Allow response for the owner; not-found denial for other users.
     */
    public function manage(User $user, SyncTarget $target): Response
    {
        return $user->id === $target->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
