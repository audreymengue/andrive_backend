<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmCourseRequest;
use App\Http\Requests\FinishCourseRequest;
use App\Http\Requests\IndexCourseRequest;
use App\Http\Requests\StartCourseRequest;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCoursePreparationRequest;
use App\Services\CoursePreparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(private readonly CoursePreparationService $service) {}

    public function index(IndexCourseRequest $request): JsonResponse
    {
        return $this->service->listCourses($request);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        return $this->service->create($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->service->show($request, $id);
    }

    public function update(UpdateCoursePreparationRequest $request, int $id): JsonResponse
    {
        return $this->service->updatePreparation($request, $id);
    }

    public function confirm(ConfirmCourseRequest $request, int $id): JsonResponse
    {
        return $this->service->confirm($request, $id);
    }

    public function start(StartCourseRequest $request, int $id): JsonResponse
    {
        return $this->service->start($request, $id);
    }

    public function finish(FinishCourseRequest $request, int $id): JsonResponse
    {
        return $this->service->finish($request, $id);
    }
}
