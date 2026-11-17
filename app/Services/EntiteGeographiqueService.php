<?php

namespace App\Services;

use App\Http\Requests\ListOperationalCatalogRequest;
use App\Http\Requests\ListReferenceCatalogRequest;
use App\Models\DemandeModification;
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
        $query = EntiteGeographique::query()->where('statut', 'ACTIF')->orderBy('id')->withExists([
            'demandesAuDepart',
            'demandesAuRetour',
            'destinations',
            'modificationsAuDepart',
            'modificationsAuRetour',
        ]);
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $builder) => $builder->where('adresse', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }

        return $this->includeModificationDestinationUsage($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * @return LengthAwarePaginator<int, EntiteGeographique>
     */
    public function adminList(ListReferenceCatalogRequest $request): LengthAwarePaginator
    {
        $query = EntiteGeographique::query()->orderBy('id')->withExists([
            'demandesAuDepart',
            'demandesAuRetour',
            'destinations',
            'modificationsAuDepart',
            'modificationsAuRetour',
        ]);
        if ($request->filled('statut')) {
            $query->where('statut', $request->validated('statut'));
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $builder) => $builder->where('adresse', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }

        return $this->includeModificationDestinationUsage($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * @param  LengthAwarePaginator<int, EntiteGeographique>  $page
     * @return LengthAwarePaginator<int, EntiteGeographique>
     */
    private function includeModificationDestinationUsage(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $ids = $page->getCollection()->modelKeys();
        if ($ids === []) {
            return $page;
        }

        $jsonDestinations = DemandeModification::query()
            ->where(function (Builder $builder) use ($ids): void {
                foreach ($ids as $id) {
                    $builder->orWhereJsonContains('destinations', ['entite_geographique_id' => (int) $id]);
                }
            })
            ->pluck('destinations');
        $usedIds = $jsonDestinations->flatMap(function (mixed $json) use ($ids): array {
            $destinations = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : $json;

            return collect($destinations)->filter(fn (array $destination): bool => in_array((int) $destination['entite_geographique_id'], $ids, true))
                ->pluck('entite_geographique_id')->map(fn (int|string $id): int => (int) $id)->all();
        })->unique()->all();
        foreach ($page->getCollection() as $entity) {
            $entity->setAttribute('modification_destinations_exists', in_array((int) $entity->id, $usedIds, true));
        }

        return $page;
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
        return $entity->demandesAuDepart()->exists()
            || $entity->demandesAuRetour()->exists()
            || $entity->destinations()->exists()
            || $entity->modificationsAuDepart()->exists()
            || $entity->modificationsAuRetour()->exists()
            || DemandeModification::query()->whereJsonContains('destinations', ['entite_geographique_id' => (int) $entity->id])->exists();
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
