<?php

namespace App\Services;

use App\Models\TypeSortie;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;

class TypeSortieService
{
    /**
     * @return Collection<int, TypeSortie>
     */
    public function list(): Collection
    {
        return TypeSortie::query()->orderBy('id')->get();
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
        if ($typeSortie->demandes()->exists() || $typeSortie->modifications()->exists()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Ce type de sortie ne peut pas être supprimé car il est utilisé par des demandes.',
            ], 409));
        }

        $typeSortie->delete();
    }
}
