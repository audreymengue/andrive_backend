<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushDeviceRequest;
use App\Services\PushDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PushDeviceController extends Controller
{
    public function update(PushDeviceRequest $request, PushDeviceService $devices): JsonResponse
    {
        $values = $request->validated();
        $devices->register($request->user(), strtolower($values['installation_id']), $values['plateforme'], $values['token']);

        return response()->json(['message' => 'Appareil enregistré.']);
    }

    public function destroy(PushDeviceRequest $request, PushDeviceService $devices): Response
    {
        $devices->remove($request->user(), strtolower($request->validated('installation_id')));

        return response()->noContent();
    }
}
