<?php

namespace App\Models;

use Database\Factories\EntiteGeographiqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntiteGeographique extends Model
{
    /** @use HasFactory<EntiteGeographiqueFactory> */
    use HasFactory;

    protected $table = 'entites_geographiques';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'adresse',
        'latitude',
        'longitude',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'lock_version' => 'integer',
        ];
    }

    public function demandesAuDepart(): HasMany
    {
        return $this->hasMany(Demande::class, 'adresse_depart_id');
    }

    public function demandesAuRetour(): HasMany
    {
        return $this->hasMany(Demande::class, 'adresse_retour_id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(DemandeDestination::class);
    }

    public function modificationsAuDepart(): HasMany
    {
        return $this->hasMany(DemandeModification::class, 'adresse_depart_id');
    }

    public function modificationsAuRetour(): HasMany
    {
        return $this->hasMany(DemandeModification::class, 'adresse_retour_id');
    }
}
