<?php

namespace App\Services;

use App\Http\Requests\ListOperationalCatalogRequest;
use App\Http\Requests\ListReferenceCatalogRequest;
use App\Models\EntiteGeographique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;

class EntiteGeographiqueService
{
    /**
     * @return Collection<int, EntiteGeographique>
     */
    public function list(): Collection
    {
        return EntiteGeographique::query()->where('statut', 'ACTIF')->orderBy('id')->get();
    }

    /**
     * @return LengthAwarePaginator<int, EntiteGeographique>
     */
    public function operationalList(ListOperationalCatalogRequest $request): LengthAwarePaginator
    {
        $query = EntiteGeographique::query()->where('statut', 'ACTIF')->orderBy('id');
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $builder) => $builder->where('adresse', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }

        return $query->paginate($request->integer('per_page', 20));
    }

    /**
     * @return LengthAwarePaginator<int, EntiteGeographique>
     */
    public function adminList(ListReferenceCatalogRequest $request): LengthAwarePaginator
    {
        $query = EntiteGeographique::query()->orderBy('id');
        if ($request->filled('statut')) {
            $query->where('statut', $request->validated('statut'));
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $builder) => $builder->where('adresse', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }

        return $query->paginate($request->integer('per_page', 20));
    }

    /**
     * @param  array{adresse: string, latitude?: numeric|null, longitude?: numeric|null, description?: string|null}  $data
     */
    public function create(array $data): EntiteGeographique
    {
        return EntiteGeographique::query()->create($data);
    }

    public function find(int $id): EntiteGeographique
    {
        return EntiteGeographique::query()->findOrFail($id);
    }

    public function changeStatus(int $id, string $status): EntiteGeographique
    {
        $entity = $this->find($id);
        if ($entity->statut !== $status) {
            $entity->forceFill(['statut' => $status])->save();
        }

        return $entity->fresh();
    }

    public function isUsed(EntiteGeographique $entity): bool
    {
        return $entity->usage_observe_at !== null;
    }

    /**
     * @param  array{adresse?: string, latitude?: numeric|null, longitude?: numeric|null, description?: string|null}  $data
     */
    public function update(EntiteGeographique $entity, array $data): EntiteGeographique
    {
        $entity->update($data);

        return $entity;
    }

    public function delete(EntiteGeographique $entity): void
    {
        if ($this->isUsed($entity)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Cette entité géographique ne peut pas être supprimée car elle est utilisée par des demandes.',
                'code' => 'REFERENCE_IN_USE',
            ], 409));
        }

        $entity->delete();
    }
}
