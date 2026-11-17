<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\SyncUserRolesRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(private UserService $userService) {}

    public function index(ListUsersRequest $request): JsonResponse
    {
        $users = $this->userService->list($request->validated());

        return response()->json([
            'message' => 'Liste des utilisateurs récupérée avec succès.',
            'data' => UserResource::collection($users->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $result = $this->userService->create($request->validated());

        return response()->json([
            'message' => 'Utilisateur créé avec succès.',
            'data' => new UserResource($result['user']->load('roles.permissions')),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->userService->find($id);

        return $this->userResponse(
            $user,
            'Utilisateur récupéré avec succès.',
        );
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = $this->userService->update($this->userService->find($id), $request->validated());

        return $this->userResponse($user, 'Utilisateur modifié avec succès.');
    }

    public function activate(int $id): JsonResponse
    {
        $user = $this->userService->activate($this->userService->find($id));

        return $this->userResponse($user, 'Utilisateur activé avec succès.');
    }

    public function deactivate(int $id): JsonResponse
    {
        $user = $this->userService->deactivate($this->userService->find($id));

        return $this->userResponse($user, 'Utilisateur désactivé avec succès.');
    }

    public function resetPassword(int $id): JsonResponse
    {
        $this->userService->resetPassword($this->userService->find($id));

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès.',
            'data' => [
                'must_change_password' => true,
            ],
        ]);
    }

    public function syncRoles(SyncUserRolesRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $user = $this->userService->syncRoles($this->userService->find($id), $data['role_ids']);

        return $this->userResponse($user, 'Rôles assignés avec succès.');
    }

    private function userResponse(User $user, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new UserResource($user->load('roles.permissions')),
        ], $status);
    }
}
