<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSyncTargetRequest;
use App\Http\Resources\SyncTargetResource;
use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SyncTargetController extends Controller
{
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

    public function store(StoreSyncTargetRequest $request): RedirectResponse
    {
        $request->user()->syncTargets()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Target added. You can start the synchronization now.')]);

        return to_route('targets.index');
    }

    /**
     * Queue a synchronization. Returns immediately; the worker does the actual work.
     */
    public function sync(SyncTarget $target): RedirectResponse
    {
        Gate::authorize('manage', $target);

        // markQueued() is an atomic claim: if a sync is already pending or running
        // (or a double click raced us), nothing is dispatched a second time.
        if ($target->markQueued()) {
            SyncTargetJob::dispatch($target);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Synchronization started.')]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('A synchronization is already in progress.')]);
        }

        return to_route('targets.index');
    }

    /**
     * Stop a synchronization that has not started yet (queued, or waiting for a rate limit).
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
