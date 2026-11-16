<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\SyncRolePermissionsRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'statut' => ['nullable', 'in:ACTIF,INACTIF'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $roles = Role::query()->with('permissions')
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($data['statut'] ?? null, fn ($query, $statut) => $query->where('statut', $statut))
            ->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json([
            'message' => 'Liste des rôles récupérée avec succès.',
            'data' => RoleResource::collection($roles->getCollection())->resolve($request),
            'meta' => ['current_page' => $roles->currentPage(), 'per_page' => $roles->perPage(), 'total' => $roles->total(), 'last_page' => $roles->lastPage()],
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = Role::query()->create([
            'name' => $data['nom'],
            'guard_name' => 'web',
            'description' => $data['description'] ?? null,
            'statut' => $data['statut'] ?? 'ACTIF',
        ]);

        return $this->roleResponse($role, 'Rôle créé avec succès.', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->roleResponse($this->findRole($id), 'Rôle récupéré avec succès.');
    }

    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        $role = $this->findRole($id);
        $data = $request->validated();
        $role->update([
            'name' => $data['nom'] ?? $role->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
            'statut' => $data['statut'] ?? $role->statut,
        ]);

        return $this->roleResponse($role, 'Rôle modifié avec succès.');
    }

    public function destroy(int $id): JsonResponse|Response
    {
        $role = $this->findRole($id);

        if ($role->users()->exists() || $role->permissions()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer ce rôle car il est encore utilisé.',
            ], 409);
        }

        $role->delete();

        return response()->noContent();
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, int $id): JsonResponse
    {
        $role = $this->findRole($id);
        $permissions = Permission::query()
            ->whereIn('id', $request->validated('permission_ids'))
            ->get();

        if ($permissions->count() !== count($request->validated('permission_ids'))) {
            throw (new ModelNotFoundException)->setModel(Permission::class, $request->validated('permission_ids'));
        }

        $role->syncPermissions($permissions);

        return $this->roleResponse($role, 'Permissions assignées avec succès.');
    }

    private function findRole(int $id): Role
    {
        return Role::query()->with('permissions')->findOrFail($id);
    }

    private function roleResponse(Role $role, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new RoleResource($role->load('permissions')),
        ], $status);
    }
}
