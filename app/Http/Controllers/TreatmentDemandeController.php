<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTreatmentDemandesRequest;
use App\Services\CoursePreparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreatmentDemandeController extends Controller
{
    public function __construct(private readonly CoursePreparationService $service) {}

    public function index(ListTreatmentDemandesRequest $request): JsonResponse
    {
        return $this->service->listPendingDemandes($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->service->showPendingDemande($request, $id);
    }
}
