<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'nom', 'prenom', 'email', 'password', 'temporary_password', 'statut', 'direction_id', 'service_id', 'must_change_password'])]
#[Hidden(['password', 'temporary_password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use HasRoles { hasRole as protected originalHasRole; }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'lock_version' => 'integer',
            'password_generation' => 'integer',
            'direction_id' => 'integer',
            'service_id' => 'integer',
            'must_change_password' => 'boolean',
        ];
    }

    public function hasPermissionTo(mixed $permission, ?string $guardName = null): bool
    {
        $permission = $this->filterPermission($permission, $guardName);

        return $this->permissions()->whereKey($permission->id)->exists()
            || $this->roles()->where('statut', 'ACTIF')->whereHas('permissions', fn ($query) => $query->whereKey($permission->id))->exists();
    }

    public function getPermissionsViaRoles(): Collection
    {
        return $this->roles()->where('statut', 'ACTIF')->with('permissions')->get()->flatMap(fn ($role) => $role->permissions)->unique('id')->values();
    }

    public function getAllPermissions(): Collection
    {
        return $this->permissions()->get()->merge($this->getPermissionsViaRoles())->unique('id')->values();
    }

    public function hasRole(mixed $roles, ?string $guard = null): bool
    {
        if ($roles === 'super_admin') {
            return $this->roles()->where('system_key', 'super_admin')->where('statut', 'ACTIF')->when($guard, fn ($query) => $query->where('guard_name', $guard))->exists();
        }

        return $this->originalHasRole($roles, $guard);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function chauffeurProfile(): HasOne
    {
        return $this->hasOne(Chauffeur::class);
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class, 'demandeur_id');
    }
}
