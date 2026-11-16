<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListParametrageJournalRequest;
use App\Http\Resources\OrganisationHistoryResource;
use App\Http\Resources\ParametrageAuditResource;
use App\Services\ParametrageJournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ParametrageJournalController extends Controller
{
    public function __construct(private ParametrageJournalService $journal) {}

    public function index(ListParametrageJournalRequest $request): JsonResponse
    {
        $page = $this->journal->audit($request->validated());

        return $this->listResponse($page, ParametrageAuditResource::collection($page->getCollection())->resolve($request));
    }

    public function history(ListParametrageJournalRequest $request, string $id): JsonResponse
    {
        $page = $this->journal->history($id, $request->validated());

        return $this->listResponse($page, OrganisationHistoryResource::collection($page->getCollection())->resolve($request));
    }

    public function show(ListParametrageJournalRequest $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Événement récupéré avec succès.', 'data' => (new ParametrageAuditResource($this->journal->findAudit($id)))->resolve($request)])->header('Cache-Control', 'no-store');
    }

    /** @param list<array<string, mixed>> $data */
    private function listResponse(LengthAwarePaginator $page, array $data): JsonResponse
    {
        return response()->json(['message' => 'Liste récupérée avec succès.', 'data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]])->header('Cache-Control', 'no-store');
    }
}
