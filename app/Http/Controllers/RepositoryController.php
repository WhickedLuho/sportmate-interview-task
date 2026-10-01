<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRepositoriesRequest;
use App\Http\Resources\RepositoryResource;
use App\Models\Repository;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    private const PER_PAGE = 25;

    public function index(ListRepositoriesRequest $request): Response
    {
        $user = $request->user();

        // Every query is scoped to the signed-in user's targets.
        $owned = Repository::query()->whereIn('sync_target_id', $user->syncTargets()->select('id'));

        $repositories = (clone $owned)
            ->with('syncTarget:id,name')
            ->when($request->validated('target'), fn ($query, $target) => $query->where('sync_target_id', $target))
            ->when($request->validated('language'), fn ($query, $language) => $query->where('language', $language))
            ->when($request->validated('search'), fn ($query, $search) => $query->where(
                fn ($query) => $query
                    ->whereLike('full_name', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%"),
            ))
            // Repositories GitHub stopped returning are hidden unless asked for.
            ->unless($request->showMissing(), fn ($query) => $query->whereNull('missing_at'))
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('repositories/Index', [
            'repositories' => RepositoryResource::collection($repositories),
            'filters' => [
                'search' => $request->validated('search') ?? '',
                'target' => $request->validated('target'),
                'language' => $request->validated('language') ?? '',
                'sort' => $request->sortKey(),
                'direction' => $request->sortDirection(),
                'missing' => $request->showMissing(),
            ],
            'targets' => $user->syncTargets()->orderBy('name')->get(['id', 'name']),
            'languages' => (clone $owned)->whereNotNull('language')->distinct()->orderBy('language')->pluck('language'),
        ]);
    }
}
