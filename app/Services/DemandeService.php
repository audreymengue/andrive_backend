<?php

namespace App\Services;

use App\Models\Demande;
use App\Models\DemandeModification;
use App\Models\DemandeReferenceSequence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DemandeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function list(User $user, array $data): LengthAwarePaginator
    {
        $query = $this->detailQuery();

        if (! $this->canAccessAllDemandes($user)) {
            $query->where('demandeur_id', $user->id);
        }

        if (($search = $data['search'] ?? null) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhere('motif', 'like', "%{$search}%")
                    ->orWhere('commentaire', 'like', "%{$search}%")
                    ->orWhereHas('destinations.entiteGeographique', function (Builder $entiteQuery) use ($search): void {
                        $entiteQuery->where('adresse', 'like', "%{$search}%");
                    })
                    ->orWhereHas('employes', function (Builder $employeQuery) use ($search): void {
                        $employeQuery
                            ->where('nom', 'like', "%{$search}%")
                            ->orWhere('prenom', 'like', "%{$search}%");
                    })
                    ->orWhereHas('objets', function (Builder $objetQuery) use ($search): void {
                        $objetQuery->where('designation', 'like', "%{$search}%");
                    });
            });
        }

        if (($reference = $data['reference'] ?? null) !== null) {
            $query->where('reference', 'like', "%{$reference}%");
        }

        if (($directionLibelle = $data['direction_libelle'] ?? null) !== null) {
            $query->whereHas('direction', function (Builder $directionQuery) use ($directionLibelle): void {
                $directionQuery->where('nom', 'like', "%{$directionLibelle}%");
            });
        }

        if (($demandeurNom = $data['demandeur_nom'] ?? null) !== null) {
            $query->whereHas('demandeur', function (Builder $demandeurQuery) use ($demandeurNom): void {
                $demandeurQuery
                    ->where('nom', 'like', "%{$demandeurNom}%")
                    ->orWhere('prenom', 'like', "%{$demandeurNom}%");
            });
        }

        foreach (['statut', 'direction_id', 'type_sortie_id'] as $filter) {
            if (($value = $data[$filter] ?? null) !== null) {
                $query->where($filter, $value);
            }
        }

        if ($this->canAccessAllDemandes($user) && ($demandeurId = $data['demandeur_id'] ?? null) !== null) {
            $query->where('demandeur_id', $demandeurId);
        }

        if (($dateDepartFrom = $data['date_depart_from'] ?? null) !== null) {
            $query->whereDate('date_depart', '>=', $dateDepartFrom);
        }

        if (($dateDepartTo = $data['date_depart_to'] ?? null) !== null) {
            $query->whereDate('date_depart', '<=', $dateDepartTo);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Demande
    {
        return DB::transaction(function () use ($user, $data): Demande {
            DemandeReferences::lockGuard();
            DemandeReferences::ensureActive($data);
            $demande = Demande::query()->create([
                'reference' => $this->generateReference(),
                'demandeur_id' => $user->id,
                'direction_id' => $data['direction_id'],
                'service_id' => $data['service_id'] ?? null,
                'rattachement_direction_id' => $data['rattachement_direction_id'] ?? null,
                'type_sortie_id' => $data['type_sortie_id'],
                'adresse_depart_id' => $data['adresse_depart_id'],
                'adresse_retour_id' => $data['adresse_retour_id'] ?? null,
                'date_depart' => $data['date_depart'],
                'heure_depart' => $data['heure_depart'],
                'heure_retour' => $data['heure_retour'] ?? null,
                'motif' => $data['motif'],
                'commentaire' => $data['commentaire'] ?? null,
                'statut' => Demande::STATUT_BROUILLON,
            ]);

            $this->replaceChildren($demande, $data);

            return $this->loadDetails($demande);
        });
    }

    public function find(User $user, int $id): Demande
    {
        $telephone = DB::table('course_preparation_demandes as affectation')
            ->join('course_preparations as preparation', 'preparation.id', '=', 'affectation.course_preparation_id')
            ->join('courses', 'courses.id', '=', 'preparation.course_id')
            ->join('chauffeurs', 'chauffeurs.id', '=', 'preparation.chauffeur_id')
            ->whereColumn('affectation.demande_id', 'demandes.id')
            ->where('demandes.demandeur_id', $user->id)
            ->whereIn('demandes.statut', [Demande::STATUT_VALIDEE, Demande::STATUT_REVALIDATION_REQUISE])
            ->whereIn('courses.statut_execution', ['A_VENIR', 'EN_COURS'])
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')->from('course_preparations as suivante')
                    ->whereColumn('suivante.course_id', 'preparation.course_id')
                    ->whereColumn('suivante.version', '>', 'preparation.version');
            })
            ->orderByDesc('courses.id')
            ->limit(1)
            ->select('chauffeurs.telephone');

        $demande = $this->detailQuery()
            ->addSelect(['chauffeur_telephone' => $telephone])
            ->findOrFail($id);

        $this->ensureCanRead($user, $demande);

        return $demande;
    }

    public function findByReference(User $user, string $reference): ?Demande
    {
        $demande = $this->detailQuery()->where('reference', $reference)->first();

        if ($demande !== null) {
            $this->ensureCanRead($user, $demande);
        }

        return $demande;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, int $id, array $data): Demande
    {
        return DB::transaction(function () use ($user, $id, $data): Demande {
            DemandeReferences::lockGuard();
            $demande = Demande::query()->lockForUpdate()->findOrFail($id);
            $this->ensureCanManage($user, $demande);
            $this->ensureStatus($demande, Demande::STATUT_BROUILLON, 'Seule une demande en brouillon peut être modifiée.');

            DemandeReferences::ensureActive($data, DemandeReferences::selection($demande));
            $this->fillDemande($demande, $data);
            $this->replaceChildren($demande, $data);

            return $this->loadDetails($demande);
        });
    }

    public function submit(User $user, int $id): Demande
    {
        return DB::transaction(function () use ($user, $id): Demande {
            DemandeReferences::lockGuard();
            $demande = Demande::query()->lockForUpdate()->findOrFail($id);
            $this->ensureCanManage($user, $demande);
            $this->ensureStatus($demande, Demande::STATUT_BROUILLON, 'Seule une demande en brouillon peut être soumise.');
            $this->ensureDemandeCanBeSubmitted($demande);
            DemandeReferences::ensureActive(DemandeReferences::selection($demande));

            $this->changeStatus($demande, Demande::STATUT_EN_ATTENTE, $user);

            return $this->loadDetails($demande);
        });
    }

    public function cancel(User $user, int $id, string $motif): Demande
    {
        return DB::transaction(function () use ($user, $id, $motif): Demande {
            $demande = Demande::query()->lockForUpdate()->findOrFail($id);
            $this->ensureCanManage($user, $demande);

            if ($demande->statut === Demande::STATUT_TERMINEE) {
                $this->conflict('Une demande terminée ne peut pas être annulée.');
            }

            $this->changeStatus($demande, Demande::STATUT_ANNULEE, $user, $motif);

            return $this->loadDetails($demande);
        });
    }

    public function requestModification(User $user, int $id): Demande
    {
        return DB::transaction(function () use ($user, $id): Demande {
            DemandeReferences::lockGuard();
            $demande = Demande::query()
                ->with(['destinations', 'employes', 'objets'])
                ->lockForUpdate()
                ->findOrFail($id);
            $this->ensureCanManage($user, $demande);
            $this->ensureStatus($demande, Demande::STATUT_VALIDEE, 'Seule une demande validée peut faire l’objet d’une modification.');

            if ($demande->modification()->exists()) {
                $this->conflict('Une modification est déjà en cours pour cette demande.');
            }

            $demande->modification()->create([
                'demandeur_id' => $user->id,
                'direction_id' => $demande->direction_id,
                'service_id' => $demande->service_id,
                'rattachement_direction_id' => $demande->rattachement_direction_id,
                'type_sortie_id' => $demande->type_sortie_id,
                'adresse_depart_id' => $demande->adresse_depart_id,
                'adresse_retour_id' => $demande->adresse_retour_id,
                'date_depart' => $demande->date_depart,
                'heure_depart' => $demande->heure_depart,
                'heure_retour' => $demande->heure_retour,
                'motif' => $demande->motif,
                'commentaire' => $demande->commentaire,
                'destinations' => $demande->destinations->map(fn ($destination): array => [
                    'entite_geographique_id' => $destination->entite_geographique_id,
                    'ordre' => $destination->ordre,
                ])->all(),
                'employes' => $demande->employes->map(fn ($employe): array => [
                    'nom' => $employe->nom,
                    'prenom' => $employe->prenom,
                    'fonction' => $employe->fonction,
                ])->all(),
                'objets' => $demande->objets->map(fn ($objet): array => [
                    'designation' => $objet->designation,
                    'quantite' => $objet->quantite,
                    'description' => $objet->description,
                ])->all(),
            ]);

            return $this->loadDetails($demande);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateModification(User $user, int $id, array $data): Demande
    {
        return DB::transaction(function () use ($user, $id, $data): Demande {
            DemandeReferences::lockGuard();
            $demande = Demande::query()->lockForUpdate()->findOrFail($id);
            $this->ensureCanManage($user, $demande);
            $this->ensureStatus($demande, Demande::STATUT_VALIDEE, 'La modification est incompatible avec le statut actuel de la demande.');

            $modification = $demande->modification()->whereNull('submitted_at')->first();

            if ($modification === null) {
                $this->conflict('Aucune modification en cours ne correspond à cette demande.');
            }

            DemandeReferences::ensureActive($data, DemandeReferences::selection($modification));
            $this->fillModification($modification, $data);

            return $this->loadDetails($demande);
        });
    }

    public function submitModification(User $user, int $id): Demande
    {
        return DB::transaction(function () use ($user, $id): Demande {
            DemandeReferences::lockGuard();
            $demande = Demande::query()->lockForUpdate()->findOrFail($id);
            $this->ensureCanManage($user, $demande);
            $this->ensureStatus($demande, Demande::STATUT_VALIDEE, 'La modification ne peut pas être soumise dans l’état actuel de la demande.');

            $modification = $demande->modification()->whereNull('submitted_at')->first();

            if ($modification === null) {
                $this->conflict('Aucune modification en cours ne correspond à cette demande.');
            }

            $this->ensureModificationCanBeSubmitted($modification);
            DemandeReferences::ensureActive(DemandeReferences::selection($modification));
            $modification->update(['submitted_at' => now()]);
            $this->changeStatus($demande, Demande::STATUT_REVALIDATION_REQUISE, $user);

            return $this->loadDetails($demande);
        });
    }

    private function detailQuery(): Builder
    {
        return Demande::query()->with([
            'demandeur:id,nom,prenom',
            'direction:id,nom',
            'service:id,nom,code',
            'rattachementDirection:id,nom,code',
            'typeSortie:id,libelle,code',
            'adresseDepart:id,adresse,latitude,longitude,description',
            'adresseRetour:id,adresse,latitude,longitude,description',
            'destinations.entiteGeographique:id,adresse,latitude,longitude,description',
            'employes',
            'objets',
        ]);
    }

    private function loadDetails(Demande $demande): Demande
    {
        return $demande->load([
            'demandeur:id,nom,prenom',
            'direction:id,nom',
            'service:id,nom,code',
            'rattachementDirection:id,nom,code',
            'typeSortie:id,libelle,code',
            'adresseDepart:id,adresse,latitude,longitude,description',
            'adresseRetour:id,adresse,latitude,longitude,description',
            'destinations.entiteGeographique:id,adresse,latitude,longitude,description',
            'employes',
            'objets',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillDemande(Demande $demande, array $data): void
    {
        $demande->update([
            'direction_id' => $data['direction_id'],
            'service_id' => $data['service_id'] ?? null,
            'rattachement_direction_id' => $data['rattachement_direction_id'] ?? null,
            'type_sortie_id' => $data['type_sortie_id'],
            'adresse_depart_id' => $data['adresse_depart_id'],
            'adresse_retour_id' => $data['adresse_retour_id'] ?? null,
            'date_depart' => $data['date_depart'],
            'heure_depart' => $data['heure_depart'],
            'heure_retour' => $data['heure_retour'] ?? null,
            'motif' => $data['motif'],
            'commentaire' => $data['commentaire'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replaceChildren(Demande $demande, array $data): void
    {
        $demande->destinations()->delete();
        $demande->employes()->delete();
        $demande->objets()->delete();

        $demande->destinations()->createMany($data['destinations']);
        $demande->employes()->createMany($data['employes']);
        $demande->objets()->createMany($data['objets'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillModification(DemandeModification $modification, array $data): void
    {
        $modification->update([
            'direction_id' => $data['direction_id'],
            'service_id' => $data['service_id'] ?? null,
            'rattachement_direction_id' => $data['rattachement_direction_id'] ?? null,
            'type_sortie_id' => $data['type_sortie_id'],
            'adresse_depart_id' => $data['adresse_depart_id'],
            'adresse_retour_id' => $data['adresse_retour_id'] ?? null,
            'date_depart' => $data['date_depart'],
            'heure_depart' => $data['heure_depart'],
            'heure_retour' => $data['heure_retour'] ?? null,
            'motif' => $data['motif'],
            'commentaire' => $data['commentaire'] ?? null,
            'destinations' => $data['destinations'],
            'employes' => $data['employes'],
            'objets' => $data['objets'] ?? [],
        ]);
    }

    private function generateReference(): string
    {
        $now = now();
        $referenceDate = $now->toDateString();

        DB::table('demande_reference_sequences')->insertOrIgnore([
            'reference_date' => $referenceDate,
            'last_sequence' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DemandeReferenceSequence::query()
            ->where('reference_date', $referenceDate)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw (new ModelNotFoundException)->setModel(DemandeReferenceSequence::class, [$referenceDate]);
        }

        $nextSequence = $sequence->last_sequence + 1;

        if ($nextSequence > 1000) {
            $this->conflict('La limite de 1000 demandes pour cette journée est atteinte.');
        }

        $sequence->update(['last_sequence' => $nextSequence]);

        return $now->format('d-m-y').'-'.str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    private function ensureCanRead(User $user, Demande $demande): void
    {
        if ($this->canAccessAllDemandes($user) || $demande->demandeur_id === $user->id) {
            return;
        }

        $this->forbidden();
    }

    private function ensureCanManage(User $user, Demande $demande): void
    {
        if ($this->canAccessAllDemandes($user) || $demande->demandeur_id === $user->id) {
            return;
        }

        $this->forbidden();
    }

    private function canAccessAllDemandes(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    private function ensureStatus(Demande $demande, string $expectedStatus, string $message): void
    {
        if ($demande->statut !== $expectedStatus) {
            $this->conflict($message);
        }
    }

    private function ensureDemandeCanBeSubmitted(Demande $demande): void
    {
        if (
            blank($demande->motif)
            || $demande->type_sortie_id === null
            || $demande->adresse_depart_id === null
            || ! $demande->destinations()->exists()
            || ! $demande->employes()->exists()
        ) {
            $this->conflict('La demande ne contient pas toutes les données obligatoires.');
        }
    }

    private function ensureModificationCanBeSubmitted(DemandeModification $modification): void
    {
        if (
            blank($modification->motif)
            || $modification->type_sortie_id === null
            || $modification->adresse_depart_id === null
            || ! is_array($modification->destinations)
            || $modification->destinations === []
            || ! is_array($modification->employes)
            || $modification->employes === []
        ) {
            $this->conflict('La modification ne contient pas toutes les données obligatoires.');
        }
    }

    private function changeStatus(Demande $demande, string $newStatus, User $user, ?string $commentaire = null): void
    {
        $ancienStatut = $demande->statut;

        $demande->update(['statut' => $newStatus]);

        if ($ancienStatut !== $newStatus) {
            $demande->historiques()->create([
                'ancien_statut' => $ancienStatut,
                'nouveau_statut' => $newStatus,
                'utilisateur_id' => $user->id,
                'commentaire' => $commentaire,
            ]);
        }
    }

    private function forbidden(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Vous n’avez pas la permission d’effectuer cette action.',
        ], 403));
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 409));
    }
}
