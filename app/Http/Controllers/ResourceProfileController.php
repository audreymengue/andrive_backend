<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResourceProfileRequest;
use App\Http\Resources\ChauffeurResource;
use App\Http\Resources\VehiculeResource;
use App\Services\ResourceProfileService;
use Illuminate\Http\JsonResponse;

class ResourceProfileController extends Controller
{
    public function __construct(private ResourceProfileService $profiles) {}

    public function index(ResourceProfileRequest $request): JsonResponse
    {
        $page = $this->profiles->list($request);
        $resource = $request->route('resource_profile_kind') === 'vehicule' ? VehiculeResource::class : ChauffeurResource::class;

        return response()->json([
            'message' => 'Liste récupérée avec succès.',
            'data' => $resource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(ResourceProfileRequest $request, int $id): JsonResponse
    {
        $model = $this->profiles->find($request, $id);
        $resource = $request->route('resource_profile_kind') === 'vehicule' ? VehiculeResource::class : ChauffeurResource::class;

        return response()->json(['message' => 'Ressource récupérée avec succès.', 'data' => (new $resource($model))->resolve($request)]);
    }

    public function store(ResourceProfileRequest $request): JsonResponse
    {
        return $this->profiles->mutate($request, null);
    }

    public function update(ResourceProfileRequest $request, int $id): JsonResponse
    {
        return $this->profiles->mutate($request, $id);
    }

    public function activate(ResourceProfileRequest $request, int $id): JsonResponse
    {
        return $this->profiles->mutate($request, $id);
    }

    public function deactivate(ResourceProfileRequest $request, int $id): JsonResponse
    {
        return $this->profiles->mutate($request, $id);
    }
}
