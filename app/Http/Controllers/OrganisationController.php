<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganisationRequest;
use App\Http\Resources\OrganisationResource;
use App\Services\OrganisationService;
use Illuminate\Http\JsonResponse;

class OrganisationController extends Controller
{
    public function __construct(private OrganisationService $organisations) {}

    public function index(OrganisationRequest $request): JsonResponse
    {
        $page = $this->organisations->list($request);

        return response()->json(['message' => 'Liste récupérée avec succès.', 'data' => OrganisationResource::collection($page->getCollection())->resolve($request), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(OrganisationRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Ressource récupérée avec succès.', 'data' => (new OrganisationResource($this->organisations->find($request, (int) $request->route('id'))))->resolve($request)]);
    }

    public function mutate(OrganisationRequest $request): JsonResponse
    {
        return $this->organisations->mutate($request, $request->route('id') === null ? null : (int) $request->route('id'));
    }
}
