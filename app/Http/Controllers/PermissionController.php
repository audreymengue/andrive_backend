<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $permissions = Permission::query()
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('libelle', 'like', '%'.$search.'%')))
            ->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json([
            'message' => 'Liste des permissions récupérée avec succès.',
            'data' => PermissionResource::collection($permissions->getCollection())->resolve($request),
            'meta' => ['current_page' => $permissions->currentPage(), 'per_page' => $permissions->perPage(), 'total' => $permissions->total(), 'last_page' => $permissions->lastPage()],
        ]);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $permission = Permission::query()->create([
            'name' => $data['code'],
            'guard_name' => 'web',
            'libelle' => $data['libelle'],
            'description' => $data['description'] ?? null,
        ]);

        return $this->permissionResponse($permission, 'Permission créée avec succès.', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->permissionResponse($this->findPermission($id), 'Permission récupérée avec succès.');
    }

    public function update(UpdatePermissionRequest $request, int $id): JsonResponse
    {
        $permission = $this->findPermission($id);
        $data = $request->validated();
        $permission->update([
            'name' => $data['code'] ?? $permission->name,
            'libelle' => $data['libelle'] ?? $permission->libelle,
            'description' => array_key_exists('description', $data) ? $data['description'] : $permission->description,
        ]);

        return $this->permissionResponse($permission, 'Permission modifiée avec succès.');
    }

    public function destroy(int $id): JsonResponse|Response
    {
        $permission = $this->findPermission($id);

        if ($permission->roles()->exists()) {
            return response()->json([
                'message' => 'Cette permission ne peut pas être supprimée car elle est attribuée à des rôles.',
                'code' => 'REFERENCE_IN_USE',
            ], 409);
        }

        $permission->delete();

        return response()->noContent();
    }

    private function findPermission(int $id): Permission
    {
        return Permission::query()->findOrFail($id);
    }

    private function permissionResponse(Permission $permission, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new PermissionResource($permission),
        ], $status);
    }
}
