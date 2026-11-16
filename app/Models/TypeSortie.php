<?php

namespace App\Models;

use Database\Factories\TypeSortieFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypeSortie extends Model
{
    /** @use HasFactory<TypeSortieFactory> */
    use HasFactory;

    protected $table = 'types_sortie';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'libelle',
        'code',
    ];

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class);
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(DemandeModification::class);
    }
}
