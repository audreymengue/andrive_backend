<?php

namespace App\Models;

use App\Services\DemandeReferences;
use Database\Factories\DemandeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Demande extends Model
{
    public const STATUT_BROUILLON = 'BROUILLON';

    public const STATUT_EN_ATTENTE = 'EN_ATTENTE';

    public const STATUT_VALIDEE = 'VALIDEE';

    public const STATUT_REVALIDATION_REQUISE = 'REVALIDATION_REQUISE';

    public const STATUT_REFUSEE = 'REFUSEE';

    public const STATUT_ANNULEE = 'ANNULEE';

    public const STATUT_TERMINEE = 'TERMINEE';

    /** @var list<string> */
    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_EN_ATTENTE,
        self::STATUT_VALIDEE,
        self::STATUT_REVALIDATION_REQUISE,
        self::STATUT_REFUSEE,
        self::STATUT_ANNULEE,
        self::STATUT_TERMINEE,
    ];

    /** @var non-empty-string */
    public const REFERENCE_PATTERN = '/^(?:(?:0[1-9]|[12][0-9]|3[01])-(?:0[1-9]|1[0-2])-\\d{2}-(?:000[1-9]|00[1-9]\\d|0[1-9]\\d{2}|1000))$/';

    /** @use HasFactory<DemandeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (self $record): void {
            $fields = ['type_sortie_id', 'adresse_depart_id', 'adresse_retour_id'];
            if ($record->isDirty($fields)) {
                DemandeReferences::recordUsage($record->only($fields));
            }
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
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
        'statut',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_depart' => 'date:Y-m-d',
        ];
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

    public function destinations(): HasMany
    {
        return $this->hasMany(DemandeDestination::class)->orderBy('ordre')->orderBy('id');
    }

    public function employes(): HasMany
    {
        return $this->hasMany(DemandeEmploye::class)->orderBy('id');
    }

    public function objets(): HasMany
    {
        return $this->hasMany(DemandeObjet::class)->orderBy('id');
    }

    public function historiques(): HasMany
    {
        return $this->hasMany(DemandeHistorique::class)->orderBy('id');
    }

    public function modification(): HasOne
    {
        return $this->hasOne(DemandeModification::class);
    }
}
