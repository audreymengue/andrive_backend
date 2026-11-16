<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private PasswordService $passwordService) {}

    public function list(array $data): LengthAwarePaginator
    {
        $query = User::query()->with('roles.permissions');

        if (($search = $data['search'] ?? null) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (($statut = $data['statut'] ?? null) !== null) {
            $query->where('statut', $statut);
        }

        foreach (['direction_id', 'service_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return $query
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * @return array{temporary_password: string, user: User}
     */
    public function create(array $data): array
    {
        $temporaryPassword = $this->passwordService->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $temporaryPassword): User {
            return User::query()->create([
                'name' => trim("{$data['prenom']} {$data['nom']}"),
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'email' => $data['email'],
                'password' => $this->passwordService->hash($temporaryPassword),
                'temporary_password' => $this->passwordService->encryptTemporaryPassword($temporaryPassword),
                'statut' => 'ACTIF',
                'direction_id' => $data['direction_id'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'must_change_password' => true,
            ]);
        });

        return ['temporary_password' => $temporaryPassword, 'user' => $user];
    }

    public function find(int $id): User
    {
        return User::query()->with('roles.permissions')->findOrFail($id);
    }

    public function temporaryPasswordForAuthorizedAdministrator(User $user): ?string
    {
        return $this->passwordService->temporaryPasswordForAuthorizedAdministrator($user);
    }

    public function update(User $user, array $data): User
    {
        $user->fill($data);

        if (array_key_exists('nom', $data) || array_key_exists('prenom', $data)) {
            $user->name = trim("{$user->prenom} {$user->nom}");
        }

        $user->save();

        return $user;
    }

    public function activate(User $user): User
    {
        $user->update(['statut' => 'ACTIF']);

        return $user;
    }

    public function deactivate(User $user): User
    {
        DB::transaction(function () use ($user): void {
            $user->update(['statut' => 'INACTIF']);
            $user->tokens()->delete();
        });

        return $user;
    }

    public function resetPassword(User $user): string
    {
        return $this->passwordService->reset($user);
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    public function syncRoles(User $user, array $roleIds): User
    {
        $roles = Role::query()->whereIn('id', $roleIds)->get();

        if ($roles->count() !== count($roleIds)) {
            throw (new ModelNotFoundException)->setModel(Role::class, $roleIds);
        }

        if ($roles->contains(fn (Role $role): bool => $role->statut !== 'ACTIF' && ! $user->roles()->whereKey($role->id)->exists())) {
            throw ValidationException::withMessages([
                'role_ids' => ['Un rôle inactif ne peut pas être attribué à un utilisateur.'],
            ]);
        }

        $user->syncRoles($roles);

        return $user;
    }
}
