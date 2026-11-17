<?php

namespace App\Services;

use App\Http\Requests\ListOperationalCatalogRequest;
use App\Http\Requests\ListReferenceCatalogRequest;
use App\Models\TypeSortie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;

class TypeSortieService
{
    /**
     * @return Collection<int, TypeSortie>
     */
    public function list(): Collection
    {
        return TypeSortie::query()->where('statut', 'ACTIF')->orderBy('id')->get();
    }

    /**
     * @return LengthAwarePaginator<int, TypeSortie>
     */
    public function operationalList(ListOperationalCatalogRequest $request): LengthAwarePaginator
    {
        $query = TypeSortie::query()->where('statut', 'ACTIF')->orderBy('id')->withExists(['demandes', 'modifications']);
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $builder) => $builder->where('code', 'like', '%'.$search.'%')->orWhere('libelle', 'like', '%'.$search.'%'));
        }

        return $query->paginate($request->integer('per_page', 20));
    }

    /**
     * @return LengthAwarePaginator<int, TypeSortie>
     */
    public function adminList(ListReferenceCatalogRequest $request): LengthAwarePaginator
    {
        $query = TypeSortie::query()->orderBy('id')->withExists(['demandes', 'modifications']);
        if ($request->filled('statut')) {
            $query->where('statut', $request->validated('statut'));
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $builder) => $builder->where('code', 'like', '%'.$search.'%')->orWhere('libelle', 'like', '%'.$search.'%'));
        }

        return $query->paginate($request->integer('per_page', 20));
    }

    /**
     * @param  array{libelle: string, code: string}  $data
     */
    public function create(array $data): TypeSortie
    {
        return TypeSortie::query()->create($data);
    }

    public function find(int $id): TypeSortie
    {
        return TypeSortie::query()->findOrFail($id);
    }

    public function changeStatus(int $id, string $status): TypeSortie
    {
        $typeSortie = $this->find($id);
        if ($typeSortie->statut !== $status) {
            $typeSortie->forceFill(['statut' => $status])->save();
        }

        return $typeSortie->fresh();
    }

    public function isUsed(TypeSortie $typeSortie): bool
    {
        return $typeSortie->demandes()->exists() || $typeSortie->modifications()->exists();
    }

    /**
     * @param  array{libelle?: string, code?: string}  $data
     */
    public function update(TypeSortie $typeSortie, array $data): TypeSortie
    {
        $typeSortie->update($data);

        return $typeSortie;
    }

    public function delete(TypeSortie $typeSortie): void
    {
        if ($this->isUsed($typeSortie)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Ce type de sortie ne peut pas être supprimé car il est utilisé par des demandes.',
                'code' => 'REFERENCE_IN_USE',
            ], 409));
        }

        $typeSortie->delete();
    }
}
