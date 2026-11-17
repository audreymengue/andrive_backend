<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeReferenceStatusRequest;
use App\Http\Requests\ListOperationalCatalogRequest;
use App\Http\Requests\ListReferenceCatalogRequest;
use App\Http\Requests\StoreTypeSortieRequest;
use App\Http\Requests\UpdateTypeSortieRequest;
use App\Http\Resources\ParamTypeSortieResource;
use App\Http\Resources\TypeSortieResource;
use App\Models\TypeSortie;
use App\Services\TypeSortieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TypeSortieController extends Controller
{
    public function __construct(private TypeSortieService $typeSortieService) {}

    public function index(ListOperationalCatalogRequest $request): JsonResponse
    {
        $page = $this->typeSortieService->operationalList($request);

        return response()->json([
            'message' => 'Liste des types de sortie récupérée avec succès.',
            'data' => ParamTypeSortieResource::collection($page->getCollection())->resolve($request),
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
        $page = $this->typeSortieService->adminList($request);

        return response()->json([
            'message' => 'Liste des types de sortie récupérée avec succès.',
            'data' => ParamTypeSortieResource::collection($page->getCollection())->resolve($request),
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
        return $this->parametrageStatusResponse($this->typeSortieService->changeStatus($id, 'ACTIF'), 'Type de sortie activé avec succès.');
    }

    public function deactivate(ChangeReferenceStatusRequest $request, int $id): JsonResponse
    {
        return $this->parametrageStatusResponse($this->typeSortieService->changeStatus($id, 'INACTIF'), 'Type de sortie désactivé avec succès.');
    }

    public function store(StoreTypeSortieRequest $request): JsonResponse
    {
        return $this->typeSortieResponse(
            $this->typeSortieService->create($request->validated()),
            'Type de sortie créé avec succès.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'message' => 'Type de sortie récupéré avec succès.',
            'data' => (new ParamTypeSortieResource($this->typeSortieService->find($id)))->resolve($request),
        ]);
    }

    public function update(UpdateTypeSortieRequest $request, int $id): JsonResponse
    {
        return $this->typeSortieResponse(
            $this->typeSortieService->update($this->typeSortieService->find($id), $request->safe()->only(['libelle'])),
            'Type de sortie modifié avec succès.',
        );
    }

    public function destroy(int $id): Response
    {
        $this->typeSortieService->delete($this->typeSortieService->find($id));

        return response()->noContent();
    }

    private function parametrageStatusResponse(TypeSortie $typeSortie, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => new ParamTypeSortieResource($typeSortie)]);
    }

    private function typeSortieResponse(TypeSortie $typeSortie, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new TypeSortieResource($typeSortie),
        ], $status);
    }
}
