<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelDemandeRequest;
use App\Http\Requests\CreateDemandeRequest;
use App\Http\Requests\GetDemandeStatusRequest;
use App\Http\Requests\ListDemandesRequest;
use App\Http\Requests\UpdateDemandeModificationRequest;
use App\Http\Requests\UpdateDemandeRequest;
use App\Http\Resources\DemandeResource;
use App\Models\Demande;
use App\Models\User;
use App\Services\DemandeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemandeController extends Controller
{
    public function __construct(private DemandeService $demandeService) {}

    public function index(ListDemandesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $demandes = $this->demandeService->list($user, $request->validated());

        return response()->json([
            'message' => 'Liste des demandes récupérée avec succès.',
            'data' => DemandeResource::collection($demandes->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $demandes->currentPage(),
                'per_page' => $demandes->perPage(),
                'total' => $demandes->total(),
                'last_page' => $demandes->lastPage(),
            ],
        ]);
    }

    public function store(CreateDemandeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $demande = $this->demandeService->create($user, $request->validated());

        return $this->demandeResponse($demande, 'Demande créée avec succès.', 201);
    }

    public function status(GetDemandeStatusRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $demande = $this->demandeService->findByReference($user, $request->validated('reference'));

        if ($demande === null) {
            return response()->json(['message' => 'Aucune demande ne correspond à cette référence.'], 404);
        }

        return response()->json([
            'message' => 'Statut de la demande récupéré avec succès.',
            'data' => [
                'reference' => $demande->reference,
                'statut' => $demande->statut,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->find($user, $id),
            'Demande récupérée avec succès.',
        );
    }

    public function update(UpdateDemandeRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->update($user, $id, $request->validated()),
            'Demande modifiée avec succès.',
        );
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->submit($user, $id),
            'Demande soumise avec succès.',
        );
    }

    public function cancel(CancelDemandeRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->cancel($user, $id, $request->validated('motif')),
            'Demande annulée avec succès.',
        );
    }

    public function requestModification(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->requestModification($user, $id),
            'Modification de la demande ouverte avec succès.',
        );
    }

    public function updateModification(UpdateDemandeModificationRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->updateModification($user, $id, $request->validated()),
            'Modification de la demande enregistrée avec succès.',
        );
    }

    public function submitModification(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->demandeResponse(
            $this->demandeService->submitModification($user, $id),
            'Modification de la demande soumise avec succès.',
        );
    }

    private function demandeResponse(Demande $demande, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new DemandeResource($demande),
        ], $status);
    }
}
