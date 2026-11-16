<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexResourceAvailabilityRequest;
use App\Http\Requests\IndexTransportSignalementRequest;
use App\Http\Requests\ReturnResourceAvailabilityRequest;
use App\Http\Requests\StoreResourceAvailabilityRequest;
use App\Http\Requests\StoreTransportSignalementRequest;
use App\Http\Requests\UpdateResourceAvailabilityRequest;
use App\Services\TransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportController extends Controller
{
    public function __construct(private readonly TransportService $transportService) {}

    public function signalements(IndexTransportSignalementRequest $request): JsonResponse
    {
        return $this->transportService->listSignalements($request);
    }

    public function storeSignalement(StoreTransportSignalementRequest $request): JsonResponse
    {
        return $this->transportService->createSignalement($request);
    }

    public function showSignalement(Request $request, int $id): JsonResponse
    {
        return $this->transportService->showSignalement($request, $id);
    }

    public function indisponibilites(IndexResourceAvailabilityRequest $request): JsonResponse
    {
        return $this->transportService->listIndisponibilites($request);
    }

    public function storeIndisponibilite(StoreResourceAvailabilityRequest $request): JsonResponse
    {
        return $this->transportService->createIndisponibilite($request);
    }

    public function showIndisponibilite(Request $request, int $id): JsonResponse
    {
        return $this->transportService->showIndisponibilite($request, $id);
    }

    public function updateIndisponibilite(UpdateResourceAvailabilityRequest $request, int $id): JsonResponse
    {
        return $this->transportService->updateIndisponibilite($request, $id);
    }

    public function confirmReturn(ReturnResourceAvailabilityRequest $request, int $id): JsonResponse
    {
        return $this->transportService->confirmReturn($request, $id);
    }
}
