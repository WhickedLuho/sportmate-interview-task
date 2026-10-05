<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSyncTargetRequest;
use App\Http\Resources\SyncTargetResource;
use App\Models\SyncTarget;
use App\Services\SyncDispatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SyncTargetController extends Controller
{
    /**
     * Render the signed-in user's targets with active and missing repository counts.
     *
     * @param  Request  $request  Request containing the authenticated user.
     *
     * @return Response Inertia target-list page and its display data.
     */
    public function index(Request $request): Response
    {
        $targets = $request->user()->syncTargets()
            ->withCount([
                'repositories as active_repositories_count' => fn ($query) => $query->whereNull('missing_at'),
                'repositories as missing_repositories_count' => fn ($query) => $query->whereNotNull('missing_at'),
            ])
            ->latest('id')
            ->get();

        return Inertia::render('targets/Index', [
            'targets' => SyncTargetResource::collection($targets)->resolve(),
        ]);
    }

    /**
     * Save a validated target under the signed-in user without contacting GitHub.
     *
     * @param  StoreSyncTargetRequest  $request  Request with a normalized, validated target name.
     *
     * @return RedirectResponse Redirect to the target list with a confirmation message.
     */
    public function store(StoreSyncTargetRequest $request): RedirectResponse
    {
        $request->user()->syncTargets()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Target added. You can start the synchronization now.')]);

        return to_route('targets.index');
    }

    /**
     * Authorize and enqueue a new or resumed synchronization.
     *
     * @param  SyncTarget  $target  Target selected by the route.
     * @param  SyncDispatchService  $dispatch  Service that atomically claims and queues the target.
     *
     * @return RedirectResponse Redirect to the target list with the dispatch result.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function sync(SyncTarget $target, SyncDispatchService $dispatch): RedirectResponse
    {
        Gate::authorize('manage', $target);

        // markQueued() is an atomic claim: if a sync is already pending or running
        // (or a double click raced us), nothing is dispatched a second time.
        if ($dispatch->startOrResume($target)) {

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Synchronization started.')]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('A synchronization is already in progress.')]);
        }

        return to_route('targets.index');
    }

    /**
     * Authorize and stop a queued or rate-limited synchronization.
     *
     * @param  SyncTarget  $target  Target whose waiting dispatch should be stopped.
     *
     * @return RedirectResponse Redirect to the target list with the cancellation result.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function cancel(SyncTarget $target): RedirectResponse
    {
        Gate::authorize('manage', $target);

        if ($target->markCancelled()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Synchronization stopped.')]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('There is no waiting synchronization to stop.')]);
        }

        return to_route('targets.index');
    }
}
