<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeReferenceStatusRequest;
use App\Http\Requests\ListOperationalCatalogRequest;
use App\Http\Requests\ListReferenceCatalogRequest;
use App\Http\Requests\StoreEntiteGeographiqueRequest;
use App\Http\Requests\UpdateEntiteGeographiqueRequest;
use App\Http\Resources\EntiteGeographiqueResource;
use App\Http\Resources\ParamLieuResource;
use App\Models\EntiteGeographique;
use App\Services\EntiteGeographiqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EntiteGeographiqueController extends Controller
{
    public function __construct(private EntiteGeographiqueService $entiteGeographiqueService) {}

    public function index(ListOperationalCatalogRequest $request): JsonResponse
    {
        $page = $this->entiteGeographiqueService->operationalList($request);

        return response()->json([
            'message' => 'Liste des entités géographiques récupérée avec succès.',
            'data' => ParamLieuResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function adminIndex(ListReferenceCatalogRequest $request): JsonResponse
    {
        $page = $this->entiteGeographiqueService->adminList($request);

        return response()->json([
            'message' => 'Liste des entités géographiques récupérée avec succès.',
            'data' => ParamLieuResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function activate(ChangeReferenceStatusRequest $request, int $id): JsonResponse
    {
        return $this->parametrageStatusResponse($this->entiteGeographiqueService->changeStatus($id, 'ACTIF'), 'Entité géographique activée avec succès.');
    }

    public function deactivate(ChangeReferenceStatusRequest $request, int $id): JsonResponse
    {
        return $this->parametrageStatusResponse($this->entiteGeographiqueService->changeStatus($id, 'INACTIF'), 'Entité géographique désactivée avec succès.');
    }

    public function store(StoreEntiteGeographiqueRequest $request): JsonResponse
    {
        return $this->entiteGeographiqueResponse(
            $this->entiteGeographiqueService->create($request->validated()),
            'Entité géographique créée avec succès.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'message' => 'Entité géographique récupérée avec succès.',
            'data' => (new ParamLieuResource($this->entiteGeographiqueService->find($id)))->resolve($request),
        ]);
    }

    public function update(UpdateEntiteGeographiqueRequest $request, int $id): JsonResponse
    {
        return $this->entiteGeographiqueResponse(
            $this->entiteGeographiqueService->update($this->entiteGeographiqueService->find($id), $request->safe()->only(['adresse', 'latitude', 'longitude', 'description'])),
            'Entité géographique modifiée avec succès.',
        );
    }

    public function destroy(int $id): Response
    {
        $this->entiteGeographiqueService->delete($this->entiteGeographiqueService->find($id));

        return response()->noContent();
    }

    private function parametrageStatusResponse(EntiteGeographique $entiteGeographique, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => new ParamLieuResource($entiteGeographique)]);
    }

    private function entiteGeographiqueResponse(EntiteGeographique $entiteGeographique, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new EntiteGeographiqueResource($entiteGeographique),
        ], $status);
    }
}
