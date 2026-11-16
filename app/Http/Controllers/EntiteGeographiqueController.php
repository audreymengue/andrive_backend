<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEntiteGeographiqueRequest;
use App\Http\Requests\UpdateEntiteGeographiqueRequest;
use App\Http\Resources\EntiteGeographiqueResource;
use App\Models\EntiteGeographique;
use App\Services\EntiteGeographiqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EntiteGeographiqueController extends Controller
{
    public function __construct(private EntiteGeographiqueService $entiteGeographiqueService) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Liste des entités géographiques récupérée avec succès.',
            'data' => EntiteGeographiqueResource::collection($this->entiteGeographiqueService->list())->resolve($request),
        ]);
    }

    public function store(StoreEntiteGeographiqueRequest $request): JsonResponse
    {
        return $this->entiteGeographiqueResponse(
            $this->entiteGeographiqueService->create($request->validated()),
            'Entité géographique créée avec succès.',
            201,
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->entiteGeographiqueResponse(
            $this->entiteGeographiqueService->find($id),
            'Entité géographique récupérée avec succès.',
        );
    }

    public function update(UpdateEntiteGeographiqueRequest $request, int $id): JsonResponse
    {
        return $this->entiteGeographiqueResponse(
            $this->entiteGeographiqueService->update($this->entiteGeographiqueService->find($id), $request->validated()),
            'Entité géographique modifiée avec succès.',
        );
    }

    public function destroy(int $id): Response
    {
        $this->entiteGeographiqueService->delete($this->entiteGeographiqueService->find($id));

        return response()->noContent();
    }

    private function entiteGeographiqueResponse(EntiteGeographique $entiteGeographique, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new EntiteGeographiqueResource($entiteGeographique),
        ], $status);
    }
}
