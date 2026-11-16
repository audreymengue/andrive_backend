<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTreatmentDemandesRequest;
use App\Services\CoursePreparationService;
use Illuminate\Http\JsonResponse;

class TreatmentDemandeController extends Controller
{
    public function __construct(private readonly CoursePreparationService $service) {}

    public function index(ListTreatmentDemandesRequest $request): JsonResponse
    {
        return $this->service->listPendingDemandes($request);
    }
}
