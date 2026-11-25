<?php

namespace App\Models;

use App\Services\DemandeReferences;
use Database\Factories\DemandeModificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeModification extends Model
{
    /** @use HasFactory<DemandeModificationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (self $record): void {
            $fields = ['type_sortie_id', 'adresse_depart_id', 'adresse_retour_id', 'destinations'];
            if ($record->isDirty($fields)) {
                DemandeReferences::recordUsage($record->only($fields));
            }
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'demande_id',
        'demandeur_id',
        'direction_id',
        'service_id',
        'rattachement_direction_id',
        'type_sortie_id',
        'adresse_depart_id',
        'adresse_retour_id',
        'date_depart',
        'heure_depart',
        'heure_retour',
        'motif',
        'commentaire',
        'destinations',
        'employes',
        'objets',
        'submitted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_depart' => 'date:Y-m-d',
            'destinations' => 'array',
            'employes' => 'array',
            'objets' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function rattachementDirection(): BelongsTo
    {
        return $this->belongsTo(RattachementDirection::class);
    }

    public function typeSortie(): BelongsTo
    {
        return $this->belongsTo(TypeSortie::class);
    }

    public function adresseDepart(): BelongsTo
    {
        return $this->belongsTo(EntiteGeographique::class, 'adresse_depart_id');
    }

    public function adresseRetour(): BelongsTo
    {
        return $this->belongsTo(EntiteGeographique::class, 'adresse_retour_id');
    }
}
