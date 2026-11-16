<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTypeSortieRequest;
use App\Http\Requests\UpdateTypeSortieRequest;
use App\Http\Resources\TypeSortieResource;
use App\Models\TypeSortie;
use App\Services\TypeSortieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TypeSortieController extends Controller
{
    public function __construct(private TypeSortieService $typeSortieService) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Liste des types de sortie récupérée avec succès.',
            'data' => TypeSortieResource::collection($this->typeSortieService->list())->resolve($request),
        ]);
    }

    public function store(StoreTypeSortieRequest $request): JsonResponse
    {
        return $this->typeSortieResponse(
            $this->typeSortieService->create($request->validated()),
            'Type de sortie créé avec succès.',
            201,
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->typeSortieResponse(
            $this->typeSortieService->find($id),
            'Type de sortie récupéré avec succès.',
        );
    }

    public function update(UpdateTypeSortieRequest $request, int $id): JsonResponse
    {
        return $this->typeSortieResponse(
            $this->typeSortieService->update($this->typeSortieService->find($id), $request->validated()),
            'Type de sortie modifié avec succès.',
        );
    }

    public function destroy(int $id): Response
    {
        $this->typeSortieService->delete($this->typeSortieService->find($id));

        return response()->noContent();
    }

    private function typeSortieResponse(TypeSortie $typeSortie, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new TypeSortieResource($typeSortie),
        ], $status);
    }
}
