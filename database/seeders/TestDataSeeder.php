<?php

namespace Database\Seeders;

use App\Models\Demande;
use App\Models\DemandeReferenceSequence;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TypeSortie;
use App\Models\User;
use App\Services\DemandeReferences;
use App\Services\ParametrageSecurity;
use App\Services\PasswordService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TestDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string TEST_DATABASE = 'aninf_test';

    /**
     * Run the database seeds.
     */
    public function run(PasswordService $passwordService): void
    {
        self::assertTestDatabase();

        DB::transaction(function () use ($passwordService): void {
            app(ParametrageSecurity::class)->lock();
            $this->call([
                PermissionSeeder::class,
                RoleSeeder::class,
            ]);

            $directions = $this->seedDirections();
            config()->set('aninf.initial_admin_direction_id', $directions['dsi']->id);
            $this->call(AdminUserSeeder::class);

            $users = $this->seedUsers($directions, $passwordService);
            $this->callWith(QaRoleAccountsSeeder::class, ['directionId' => $directions['dsi']->id]);
            $this->call(QaCourseResourcesSeeder::class);
            $this->seedDemandes($directions, $users);
            app(ParametrageSecurity::class)->assertAdministratorRemains();
        });
    }

    public static function assertTestDatabase(): void
    {
        $databaseName = config('database.connections.mysql.database');
        $connection = DB::connection();
        $isIsolatedTestDatabase = app()->runningUnitTests()
            && $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';

        if (
            $databaseName !== self::TEST_DATABASE
            || (! $isIsolatedTestDatabase && $connection->getDatabaseName() !== self::TEST_DATABASE)
        ) {
            throw new RuntimeException('Le seeder de test ne peut être exécuté que sur la base aninf_test.');
        }
    }

    /**
     * @return array<string, Direction>
     */
    private function seedDirections(): array
    {
        $directions = [
            'dsi' => [
                'nom' => 'Direction des Services Informatiques',
                'code' => 'DSI',
                'description' => 'Direction responsable des services informatiques.',
            ],
            'daf' => [
                'nom' => 'Direction Administrative et Financière',
                'code' => 'DAF',
                'description' => 'Direction responsable de l’administration et des finances.',
            ],
            'drh' => [
                'nom' => 'Direction des Ressources Humaines',
                'code' => 'DRH',
                'description' => 'Direction responsable de la gestion des ressources humaines.',
            ],
        ];

        return collect($directions)
            ->mapWithKeys(function (array $attributes, string $key): array {
                $direction = Direction::query()->updateOrCreate(
                    ['code' => $attributes['code']],
                    [...$attributes, 'statut' => 'ACTIF'],
                );

                return [$key => $direction];
            })
            ->all();
    }

    /**
     * @param  array<string, Direction>  $directions
     * @return array<string, User>
     */
    private function seedUsers(array $directions, PasswordService $passwordService): array
    {
        $demandeurRole = Role::query()->updateOrCreate(
            ['name' => 'demandeur', 'guard_name' => 'web'],
            [
                'description' => 'Demandeur de test du module Demande',
                'statut' => 'ACTIF',
            ],
        );
        $demandeurRole->givePermissionTo(Permission::query()
            ->whereIn('name', [
                'read_demande',
                'create_demande',
                'update_demande',
                'submit_demande',
                'cancel_demande',
            ])
            ->get());

        $superAdminRole = Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $definitions = [
            'admin_test' => [
                'name' => 'Administrateur de test',
                'nom' => 'ADMIN',
                'prenom' => 'Test',
                'email' => 'admin-test@aninf.ga',
                'password' => 'TestPass1!',
                'statut' => 'ACTIF',
                'direction' => 'dsi',
                'role' => $superAdminRole,
            ],
            'demandeur1' => [
                'name' => 'Max OBAME',
                'nom' => 'OBAME',
                'prenom' => 'Max',
                'email' => 'demandeur1@aninf.ga',
                'password' => 'TestPass2!',
                'statut' => 'ACTIF',
                'direction' => 'dsi',
                'role' => $demandeurRole,
            ],
            'demandeur2' => [
                'name' => 'Jean MBOUROU',
                'nom' => 'MBOUROU',
                'prenom' => 'Jean',
                'email' => 'demandeur2@aninf.ga',
                'password' => 'TestPass3!',
                'statut' => 'ACTIF',
                'direction' => 'daf',
                'role' => $demandeurRole,
            ],
            'demandeur3' => [
                'name' => 'Marie NSONA',
                'nom' => 'NSONA',
                'prenom' => 'Marie',
                'email' => 'demandeur3@aninf.ga',
                'password' => 'TestPass4!',
                'statut' => 'ACTIF',
                'direction' => 'drh',
                'role' => $demandeurRole,
            ],
            'inactif' => [
                'name' => 'Utilisateur Inactif',
                'nom' => 'INACTIF',
                'prenom' => 'Utilisateur',
                'email' => 'inactif-test@aninf.ga',
                'password' => 'TestPass5!',
                'statut' => 'INACTIF',
                'direction' => 'dsi',
                'role' => $demandeurRole,
            ],
        ];

        return collect($definitions)
            ->mapWithKeys(function (array $attributes, string $key) use ($directions, $passwordService): array {
                /** @var Role $role */
                $role = $attributes['role'];
                /** @var string|null $directionKey */
                $directionKey = $attributes['direction'];

                $existing = User::query()->where('email', $attributes['email'])->first();
                $before = $existing?->only(['id', 'nom', 'prenom', 'statut', 'direction_id', 'lock_version']);
                if ($before !== null) {
                    $before['role_ids'] = $existing->roles()->orderBy('id')->pluck('roles.id')->all();
                }
                $user = User::query()->firstOrCreate(
                    ['email' => $attributes['email']],
                    [
                        'name' => $attributes['name'],
                        'nom' => $attributes['nom'],
                        'prenom' => $attributes['prenom'],
                        'password' => $passwordService->hash($attributes['password']),
                        'statut' => $attributes['statut'],
                        'direction_id' => $directionKey === null ? null : $directions[$directionKey]->id,
                        'must_change_password' => false,
                        'temporary_password' => null,
                    ],
                );
                $user->assignRole($role);
                $user->refresh();
                $after = $user->only(['id', 'nom', 'prenom', 'statut', 'direction_id', 'lock_version']);
                $after['role_ids'] = $user->roles()->orderBy('id')->pluck('roles.id')->all();
                if ($before !== $after) {
                    $user->forceFill(['lock_version' => $before === null ? 0 : $user->lock_version + 1])->save();
                    $after['lock_version'] = $user->lock_version;
                    DB::table('parametrage_maintenance_audits')->insert(['origine' => self::class, 'object_type' => 'user', 'object_id' => $user->id, 'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_values' => json_encode($after, JSON_THROW_ON_ERROR), 'command_key' => (string) Str::uuid(), 'created_at' => now()]);
                }

                return [$key => $user];
            })
            ->all();
    }

    /**
     * @param  array<string, Direction>  $directions
     * @param  array<string, User>  $users
     */
    private function seedDemandes(array $directions, array $users): void
    {
        $referenceDate = now();
        $existingReference = Demande::query()
            ->where('commentaire', 'Donnée de test pour Postman.')
            ->orderBy('id')
            ->value('reference');

        if ($existingReference !== null) {
            $referenceDate = Carbon::createFromFormat('!d-m-y', substr($existingReference, 0, 8));
        }

        $typeSortie = TypeSortie::query()->firstOrCreate(['code' => 'TEST_SORTIE'], ['libelle' => 'Type de sortie de démonstration TEST']);
        $depart = EntiteGeographique::query()->firstOrCreate(['adresse' => 'Siège ANINF — démonstration'], ['description' => 'Adresse de test']);
        $retour = EntiteGeographique::query()->firstOrCreate(['adresse' => 'Point de retour — démonstration'], ['description' => 'Adresse de test']);

        foreach ($this->demandeDefinitions(now()) as $definition) {
            $demandeur = $users[$definition['demandeur']];
            $direction = $directions[$definition['direction']];
            $reference = $this->referenceFor($referenceDate, $definition['sequence']);

            $demande = Demande::query()->updateOrCreate(
                ['reference' => $reference],
                [
                    'demandeur_id' => $demandeur->id,
                    'direction_id' => $direction->id,
                    'type_sortie_id' => $typeSortie->id,
                    'adresse_depart_id' => $depart->id,
                    'adresse_retour_id' => $definition['heure_retour'] === null ? null : $retour->id,
                    'date_depart' => $definition['date_depart'],
                    'heure_depart' => $definition['heure_depart'],
                    'heure_retour' => $definition['heure_retour'],
                    'motif' => $definition['motif'],
                    'commentaire' => $definition['commentaire'],
                    'statut' => $definition['statut'],
                ],
            );

            $this->seedChildren($demande, $definition);
            DemandeReferences::recordUsage(DemandeReferences::selection($demande));
            $this->seedHistoriques($demande, $definition['historiques'], $users);

            if (isset($definition['modification'])) {
                $this->seedModification($demande, $demandeur, $direction, $definition['modification']);
            }
        }

        $this->synchronizeReferenceSequence($referenceDate);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function demandeDefinitions(Carbon $referenceDate): array
    {
        $yesterday = $referenceDate->copy()->subDay()->toDateString();
        $today = $referenceDate->toDateString();
        $tomorrow = $referenceDate->copy()->addDay()->toDateString();
        $inTwoDays = $referenceDate->copy()->addDays(2)->toDateString();
        $inSixDays = $referenceDate->copy()->addDays(6)->toDateString();
        $inElevenDays = $referenceDate->copy()->addDays(11)->toDateString();

        return [
            $this->demandeDefinition(1, 'demandeur1', 'dsi', $yesterday, '08:00:00', '12:00:00', 'Préparation de la réunion technique.', Demande::STATUT_BROUILLON, [['libelle' => 'Centre-ville de Libreville', 'ordre' => 1]], [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service']], [], []),
            $this->demandeDefinition(2, 'demandeur2', 'daf', $today, '09:00:00', null, 'Dépôt de documents administratifs.', Demande::STATUT_BROUILLON, [['libelle' => 'Owendo', 'ordre' => 1]], [['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Ingénieur']], [['designation' => 'Documents administratifs', 'quantite' => null, 'description' => 'Dossiers de mission']], []),
            $this->demandeDefinition(3, 'demandeur3', 'drh', $tomorrow, '07:30:00', '15:30:00', 'Participation à une formation.', Demande::STATUT_EN_ATTENTE, [['libelle' => 'Akanda', 'ordre' => 1], ['libelle' => 'Nkok', 'ordre' => 2]], [['nom' => 'NSONA', 'prenom' => 'Marie', 'fonction' => null]], [], $this->historiques('demandeur3', Demande::STATUT_EN_ATTENTE)),
            $this->demandeDefinition(4, 'demandeur1', 'dsi', $inTwoDays, '08:00:00', '17:00:00', 'Installation de matériel réseau.', Demande::STATUT_EN_ATTENTE, [['libelle' => 'Nkok', 'ordre' => 1]], [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service'], ['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Ingénieur']], [['designation' => 'Matériel informatique', 'quantite' => 3, 'description' => 'Équipements réseau']], $this->historiques('demandeur1', Demande::STATUT_EN_ATTENTE)),
            $this->demandeDefinition(5, 'demandeur2', 'daf', $inSixDays, '10:00:00', '16:00:00', 'Réunion de coordination financière.', Demande::STATUT_EN_ATTENTE, [['libelle' => 'Centre-ville de Libreville', 'ordre' => 1], ['libelle' => 'Owendo', 'ordre' => 2]], [['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Ingénieur']], [['designation' => 'Dossiers de mission', 'quantite' => 1, 'description' => null]], $this->historiques('demandeur2', Demande::STATUT_EN_ATTENTE)),
            $this->demandeDefinition(6, 'demandeur1', 'dsi', $inElevenDays, '06:30:00', '18:00:00', 'Audit des équipements du site.', Demande::STATUT_VALIDEE, [['libelle' => 'Akanda', 'ordre' => 1], ['libelle' => 'Owendo', 'ordre' => 2], ['libelle' => 'Nkok', 'ordre' => 3]], [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service']], [['designation' => 'Équipements', 'quantite' => 2, 'description' => 'Matériel à inventorier']], $this->historiques('admin_test', Demande::STATUT_VALIDEE), [
                'date_depart' => $inElevenDays,
                'heure_depart' => '07:00:00',
                'heure_retour' => '18:30:00',
                'motif' => 'Audit des équipements avec itinéraire ajusté.',
                'commentaire' => 'Modification prête à être soumise pour revalidation.',
                'destinations' => [['libelle' => 'Akanda', 'ordre' => 1], ['libelle' => 'Owendo', 'ordre' => 2]],
                'employes' => [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service']],
                'objets' => [['designation' => 'Équipements', 'quantite' => 2, 'description' => 'Matériel à inventorier']],
            ]),
            $this->demandeDefinition(7, 'demandeur2', 'daf', $inTwoDays, '09:00:00', '14:00:00', 'Rendez-vous avec le fournisseur.', Demande::STATUT_VALIDEE, [['libelle' => 'Port-Gentil', 'ordre' => 1]], [['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Ingénieur']], [], $this->historiques('admin_test', Demande::STATUT_VALIDEE)),
            $this->demandeDefinition(8, 'demandeur3', 'drh', $inSixDays, '08:30:00', '16:00:00', 'Mise à jour du dossier du personnel.', Demande::STATUT_REVALIDATION_REQUISE, [['libelle' => 'Centre-ville de Libreville', 'ordre' => 1], ['libelle' => 'Akanda', 'ordre' => 2]], [['nom' => 'NSONA', 'prenom' => 'Marie', 'fonction' => null]], [['designation' => 'Documents administratifs', 'quantite' => 1, 'description' => 'Dossiers du personnel']], $this->historiques('admin_test', Demande::STATUT_REVALIDATION_REQUISE)),
            $this->demandeDefinition(9, 'demandeur1', 'dsi', $tomorrow, '11:00:00', '15:00:00', 'Visite de site non retenue.', Demande::STATUT_REFUSEE, [['libelle' => 'Nkok', 'ordre' => 1]], [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service']], [], $this->historiques('admin_test', Demande::STATUT_REFUSEE)),
            $this->demandeDefinition(10, 'demandeur2', 'daf', $yesterday, '08:00:00', '13:00:00', 'Mission annulée par la direction.', Demande::STATUT_ANNULEE, [['libelle' => 'Owendo', 'ordre' => 1]], [['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Ingénieur']], [['designation' => 'Dossiers de mission', 'quantite' => 1, 'description' => null]], $this->historiques('demandeur2', Demande::STATUT_ANNULEE)),
            $this->demandeDefinition(11, 'demandeur3', 'drh', $yesterday, '07:00:00', '19:00:00', 'Mission de terrain achevée.', Demande::STATUT_TERMINEE, [['libelle' => 'Port-Gentil', 'ordre' => 1], ['libelle' => 'Akanda', 'ordre' => 2], ['libelle' => 'Owendo', 'ordre' => 3]], [['nom' => 'NSONA', 'prenom' => 'Marie', 'fonction' => null]], [['designation' => 'Équipements', 'quantite' => 1, 'description' => 'Matériel de démonstration']], $this->historiques('admin_test', Demande::STATUT_TERMINEE)),
            $this->demandeDefinition(12, 'demandeur1', 'dsi', $inElevenDays, '08:00:00', '17:30:00', 'Préparation d’une mission inter-direction.', Demande::STATUT_BROUILLON, [['libelle' => 'Centre-ville de Libreville', 'ordre' => 1], ['libelle' => 'Nkok', 'ordre' => 2]], [['nom' => 'OBAME', 'prenom' => 'Max', 'fonction' => 'Chef de service'], ['nom' => 'NSONA', 'prenom' => 'Marie', 'fonction' => null]], [['designation' => 'Matériel informatique', 'quantite' => 1, 'description' => 'Ordinateur portable']], []),
        ];
    }

    /**
     * @param  list<array{libelle: string, ordre: int}>  $destinations
     * @param  list<array{nom: string, prenom: string, fonction: string|null}>  $employes
     * @param  list<array{designation: string, quantite: int|null, description: string|null}>  $objets
     * @param  list<array{ancien_statut: string|null, nouveau_statut: string, utilisateur: string, commentaire: string|null}>  $historiques
     * @param  array<string, mixed>|null  $modification
     * @return array<string, mixed>
     */
    private function demandeDefinition(int $sequence, string $demandeur, string $direction, string $dateDepart, string $heureDepart, ?string $heureRetour, string $motif, string $statut, array $destinations, array $employes, array $objets, array $historiques, ?array $modification = null): array
    {
        $definition = [
            'sequence' => $sequence,
            'demandeur' => $demandeur,
            'direction' => $direction,
            'date_depart' => $dateDepart,
            'heure_depart' => $heureDepart,
            'heure_retour' => $heureRetour,
            'motif' => $motif,
            'commentaire' => 'Donnée de test pour Postman.',
            'statut' => $statut,
            'destinations' => $destinations,
            'employes' => $employes,
            'objets' => $objets,
            'historiques' => $historiques,
        ];

        if ($modification !== null) {
            $definition['modification'] = $modification;
        }

        return $definition;
    }

    /**
     * @return list<array{ancien_statut: string|null, nouveau_statut: string, utilisateur: string, commentaire: string|null}>
     */
    private function historiques(string $utilisateur, string $statut): array
    {
        $historiques = [[
            'ancien_statut' => Demande::STATUT_BROUILLON,
            'nouveau_statut' => Demande::STATUT_EN_ATTENTE,
            'utilisateur' => $utilisateur,
            'commentaire' => 'Demande soumise pour traitement.',
        ]];

        $transition = match ($statut) {
            Demande::STATUT_VALIDEE => Demande::STATUT_VALIDEE,
            Demande::STATUT_REVALIDATION_REQUISE => Demande::STATUT_REVALIDATION_REQUISE,
            Demande::STATUT_REFUSEE => Demande::STATUT_REFUSEE,
            Demande::STATUT_ANNULEE => Demande::STATUT_ANNULEE,
            Demande::STATUT_TERMINEE => Demande::STATUT_TERMINEE,
            default => null,
        };

        if ($transition === null) {
            return $historiques;
        }

        $historiques[] = [
            'ancien_statut' => Demande::STATUT_EN_ATTENTE,
            'nouveau_statut' => Demande::STATUT_VALIDEE,
            'utilisateur' => $utilisateur,
            'commentaire' => 'Demande validée.',
        ];

        if ($transition === Demande::STATUT_VALIDEE) {
            return $historiques;
        }

        $historiques[] = [
            'ancien_statut' => Demande::STATUT_VALIDEE,
            'nouveau_statut' => $transition,
            'utilisateur' => $utilisateur,
            'commentaire' => 'Transition de test vers '.$transition.'.',
        ];

        return $historiques;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedChildren(Demande $demande, array $definition): void
    {
        foreach ($definition['destinations'] as $destination) {
            $demande->destinations()->updateOrCreate(
                ['ordre' => $destination['ordre']],
                ['entite_geographique_id' => EntiteGeographique::query()->firstOrCreate(['adresse' => $destination['libelle']], ['description' => 'Adresse de démonstration'])->id],
            );
        }

        foreach ($definition['employes'] as $employe) {
            $demande->employes()->firstOrCreate($employe);
        }

        foreach ($definition['objets'] as $objet) {
            $demande->objets()->updateOrCreate(
                ['designation' => $objet['designation']],
                [
                    'quantite' => $objet['quantite'],
                    'description' => $objet['description'],
                ],
            );
        }
    }

    /**
     * @param  list<array{ancien_statut: string|null, nouveau_statut: string, utilisateur: string, commentaire: string|null}>  $historiques
     * @param  array<string, User>  $users
     */
    private function seedHistoriques(Demande $demande, array $historiques, array $users): void
    {
        foreach ($historiques as $historique) {
            $demande->historiques()->firstOrCreate([
                'ancien_statut' => $historique['ancien_statut'],
                'nouveau_statut' => $historique['nouveau_statut'],
                'utilisateur_id' => $users[$historique['utilisateur']]->id,
                'commentaire' => $historique['commentaire'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $modification
     */
    private function seedModification(Demande $demande, User $demandeur, Direction $direction, array $modification): void
    {
        $copy = $demande->modification()->updateOrCreate([], [
            'demandeur_id' => $demandeur->id,
            'direction_id' => $direction->id,
            'type_sortie_id' => $demande->type_sortie_id,
            'adresse_depart_id' => $demande->adresse_depart_id,
            'adresse_retour_id' => $demande->adresse_retour_id,
            'date_depart' => $modification['date_depart'],
            'heure_depart' => $modification['heure_depart'],
            'heure_retour' => $modification['heure_retour'],
            'motif' => $modification['motif'],
            'commentaire' => $modification['commentaire'],
            'destinations' => array_map(fn (array $destination): array => [
                'entite_geographique_id' => EntiteGeographique::query()->firstOrCreate(['adresse' => $destination['libelle']], ['description' => 'Adresse de démonstration'])->id,
                'ordre' => $destination['ordre'],
            ], $modification['destinations']),
            'employes' => $modification['employes'],
            'objets' => $modification['objets'],
            'submitted_at' => null,
        ]);
        DemandeReferences::recordUsage(DemandeReferences::selection($copy));
    }

    private function synchronizeReferenceSequence(Carbon $referenceDate): void
    {
        $prefix = $referenceDate->format('d-m-y').'-';
        $lastSequence = Demande::query()
            ->where('reference', 'like', $prefix.'%')
            ->pluck('reference')
            ->map(static fn (string $reference): int => (int) substr($reference, -4))
            ->max() ?? 0;

        DemandeReferenceSequence::query()->updateOrCreate(
            ['reference_date' => $referenceDate->toDateString()],
            ['last_sequence' => $lastSequence],
        );
    }

    private function referenceFor(Carbon $referenceDate, int $sequence): string
    {
        return $referenceDate->format('d-m-y').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
