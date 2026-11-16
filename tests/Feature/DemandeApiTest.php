<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\DemandeReferenceSequence;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TypeSortie;
use App\Models\User;
use Database\Seeders\ParametrageSecuritySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemandeApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demande_endpoints_require_authentication(): void
    {
        $this->getJson('/api/demandes')->assertUnauthorized();
        $this->postJson('/api/demandes', $this->validPayload())->assertUnauthorized();
    }

    public function test_create_demande_persists_a_draft_for_the_authenticated_user(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['create_demande']);
        $direction = Direction::factory()->create();

        $response = $this->withToken($token)->postJson('/api/demandes', [
            ...$this->validPayload($direction),
            'demandeur_id' => 999_999,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Demande créée avec succès.')
            ->assertJsonPath('data.demandeur.id', $user->id)
            ->assertJsonPath('data.direction.id', $direction->id)
            ->assertJsonPath('data.statut', Demande::STATUT_BROUILLON)
            ->assertJsonPath('data.destinations.1.ordre', 2)
            ->assertJsonPath('data.employes.0.nom', 'MBOUROU')
            ->assertJsonPath('data.objets.0.quantite', 2);

        $demande = Demande::query()->firstOrFail();

        $this->assertSame($user->id, $demande->demandeur_id);
        $this->assertSame($direction->id, $demande->direction_id);
        $this->assertSame(Demande::STATUT_BROUILLON, $demande->statut);
        $this->assertSame(2, $demande->destinations()->count());
        $this->assertSame(2, $demande->employes()->count());
        $this->assertSame(1, $demande->objets()->count());
    }

    public function test_create_demande_returns_403_without_the_required_permission(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous n’avez pas la permission d’effectuer cette action.');
    }

    public function test_demande_endpoints_return_403_when_a_password_change_is_required(): void
    {
        $permission = Permission::factory()->create(['name' => 'read_demande']);
        $role = Role::factory()->create();
        $role->givePermissionTo($permission);
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole($role);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/demandes')
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous devez modifier votre mot de passe avant de poursuivre.');
    }

    public function test_create_demande_returns_422_for_a_nonexistent_direction(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload(['direction_id' => 999_999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction_id');

        $this->assertDatabaseCount('demandes', 0);
    }

    public function test_create_demande_requires_the_motif_destination_and_employee(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $direction = Direction::factory()->create();

        $this->withToken($token)
            ->postJson('/api/demandes', [
                'direction_id' => $direction->id,
                'date_depart' => '2026-10-05',
                'heure_depart' => '08:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motif.0', 'Le champ motif est obligatoire.')
            ->assertJsonPath('errors.destinations.0', 'Le champ destinations est obligatoire.')
            ->assertJsonPath('errors.employes.0', 'Le champ employés est obligatoire.');
    }

    public function test_create_demande_returns_422_for_an_invalid_date_or_duplicated_destination_order(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload([
                'date_depart' => '05/10/2026',
                'destinations' => [
                    ['entite_geographique_id' => EntiteGeographique::factory()->create()->id, 'ordre' => 1],
                    ['entite_geographique_id' => EntiteGeographique::factory()->create()->id, 'ordre' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_depart.0', 'Le champ date de départ doit respecter le format Y-m-d.')
            ->assertJsonValidationErrors('destinations.0.ordre');
    }

    public function test_create_demande_returns_422_for_an_invalid_departure_time(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload(['heure_depart' => '08:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.heure_depart.0', 'Le champ heure de départ doit respecter le format H:i:s.');
    }

    public function test_create_demande_accepts_an_absent_return_time(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload(['heure_retour' => null]))
            ->assertCreated()
            ->assertJsonPath('data.heure_retour', null);
    }

    public function test_create_demande_accepts_a_return_time_after_the_departure_time(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload([
                'heure_depart' => '08:00:00',
                'heure_retour' => '08:00:01',
            ]))
            ->assertCreated();
    }

    #[DataProvider('invalidReturnTimes')]
    public function test_create_demande_rejects_a_return_time_that_is_not_after_the_departure_time(string $heureRetour): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->withToken($token)
            ->postJson('/api/demandes', $this->validPayload([
                'heure_depart' => '08:00:00',
                'heure_retour' => $heureRetour,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.heure_retour.0', 'L’heure de retour doit être postérieure à l’heure de départ.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidReturnTimes(): array
    {
        return [
            'same as departure' => ['08:00:00'],
            'before departure' => ['07:59:59'],
        ];
    }

    public function test_reference_sequences_are_daily_and_start_at_one(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);

        $this->travelTo('2026-09-23 10:00:00');

        try {
            $first = $this->withToken($token)->postJson('/api/demandes', $this->validPayload());
            $second = $this->withToken($token)->postJson('/api/demandes', $this->validPayload(['motif' => 'Deuxième mission']));

            $this->travelTo('2026-09-24 10:00:00');
            $nextDay = $this->withToken($token)->postJson('/api/demandes', $this->validPayload(['motif' => 'Mission du lendemain']));

            $first->assertCreated()->assertJsonPath('data.reference', '23-09-26-0001');
            $second->assertCreated()->assertJsonPath('data.reference', '23-09-26-0002');
            $nextDay->assertCreated()->assertJsonPath('data.reference', '24-09-26-0001');
        } finally {
            $this->travelBack();
        }
    }

    public function test_reference_sequence_allows_one_thousand_requests_then_returns_a_conflict(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $this->travelTo('2026-09-23 10:00:00');

        try {
            DemandeReferenceSequence::factory()->create([
                'reference_date' => '2026-09-23',
                'last_sequence' => 999,
            ]);

            $this->withToken($token)
                ->postJson('/api/demandes', $this->validPayload())
                ->assertCreated()
                ->assertJsonPath('data.reference', '23-09-26-1000');

            $this->withToken($token)
                ->postJson('/api/demandes', $this->validPayload(['motif' => 'Mission excédentaire']))
                ->assertConflict()
                ->assertJsonPath('message', 'La limite de 1000 demandes pour cette journée est atteinte.');
        } finally {
            $this->travelBack();
        }
    }

    public function test_reference_has_a_database_uniqueness_constraint(): void
    {
        $demande = $this->makeDemande();

        try {
            Demande::factory()->create([
                'reference' => $demande->reference,
            ]);

            $this->fail('La contrainte unique sur la référence devait empêcher cette création.');
        } catch (QueryException) {
            $this->assertDatabaseCount('demandes', 1);
        }
    }

    public function test_show_demande_returns_its_complete_details(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['read_demande']);
        $demande = $this->makeDemande($user);

        $this->withToken($token)
            ->getJson("/api/demandes/{$demande->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $demande->id)
            ->assertJsonPath('data.reference', $demande->reference)
            ->assertJsonPath('data.demandeur.id', $user->id)
            ->assertJsonPath('data.destinations.0.entite_geographique.adresse', 'Libreville')
            ->assertJsonPath('data.employes.0.prenom', 'Jean')
            ->assertJsonPath('data.objets.0.designation', 'Documents administratifs');
    }

    public function test_show_demande_returns_404_when_the_demande_does_not_exist(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande']);

        $this->withToken($token)
            ->getJson('/api/demandes/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');
    }

    public function test_show_demande_returns_404_for_a_non_numeric_identifier(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande']);

        $this->withToken($token)
            ->getJson('/api/demandes/invalide')
            ->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');
    }

    public function test_standard_user_list_is_limited_to_its_own_demandes_even_with_an_untrusted_demandeur_filter(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['read_demande']);
        $ownDemande = $this->makeDemande($user);
        $otherDemande = $this->makeDemande();

        $this->withToken($token)
            ->getJson("/api/demandes?demandeur_id={$otherDemande->demandeur_id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownDemande->id);
    }

    public function test_a_role_named_super_admin_without_system_identity_cannot_list_other_users_demandes(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['read_demande']);
        $role = $user->roles()->firstOrFail();
        $role->update(['name' => 'super_admin']);
        $own = $this->makeDemande($user);
        $other = $this->makeDemande();

        $this->assertNull($role->fresh()->system_key);
        $this->withToken($token)->getJson('/api/demandes?demandeur_id='.$other->demandeur_id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id);
    }

    public function test_super_admin_can_filter_demandes_from_multiple_directions(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande'], true);
        $firstDirection = Direction::factory()->create();
        $secondDirection = Direction::factory()->create();
        $firstDemande = $this->makeDemande(direction: $firstDirection);
        $this->makeDemande(direction: $secondDirection);

        $this->withToken($token)
            ->getJson("/api/demandes?direction_id={$firstDirection->id}&statut=BROUILLON&per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $firstDemande->id);
    }

    public function test_super_admin_can_combine_the_text_and_date_filters(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande'], true);
        $demandeur = User::factory()->create(['nom' => 'MBOUROU', 'prenom' => 'Jean']);
        $direction = Direction::factory()->create(['nom' => 'Direction Informatique']);
        $demande = $this->makeDemande($demandeur, ['date_depart' => '2026-10-05'], $direction);

        $this->withToken($token)
            ->getJson("/api/demandes?search=Libreville&reference={$demande->reference}&direction_libelle=Informatique&demandeur_nom=MBOUROU&date_depart_from=2026-10-05&date_depart_to=2026-10-05")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $demande->id);
    }

    public function test_list_demandes_returns_422_when_per_page_exceeds_one_hundred(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande']);

        $this->withToken($token)
            ->getJson('/api/demandes?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_list_demandes_returns_422_when_the_date_range_is_inconsistent(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande']);

        $this->withToken($token)
            ->getJson('/api/demandes?date_depart_from=2026-10-06&date_depart_to=2026-10-05')
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_depart_to.0', 'La date de départ maximale doit être postérieure ou égale à la date minimale.');
    }

    public function test_status_endpoint_returns_the_status_for_an_accessible_reference(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['read_demande']);
        $demande = $this->makeDemande($user, ['statut' => Demande::STATUT_EN_ATTENTE]);

        $this->withToken($token)
            ->getJson("/api/demandes/statut?reference={$demande->reference}")
            ->assertOk()
            ->assertExactJson([
                'message' => 'Statut de la demande récupéré avec succès.',
                'data' => [
                    'reference' => $demande->reference,
                    'statut' => Demande::STATUT_EN_ATTENTE,
                ],
            ]);
    }

    public function test_status_endpoint_returns_404_for_an_unknown_reference(): void
    {
        ['token' => $token] = $this->userWithPermissions(['read_demande']);

        $this->withToken($token)
            ->getJson('/api/demandes/statut?reference=23-09-26-0001')
            ->assertNotFound()
            ->assertJsonPath('message', 'Aucune demande ne correspond à cette référence.');
    }

    public function test_update_draft_replaces_its_children_without_changing_reference_or_status(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['update_demande']);
        $demande = $this->makeDemande($user);
        $originalReference = $demande->reference;
        $direction = Direction::factory()->create();

        $this->withToken($token)
            ->putJson("/api/demandes/{$demande->id}", $this->validPayload($direction, [
                'motif' => 'Mission modifiée',
                'destinations' => [['entite_geographique_id' => EntiteGeographique::factory()->create(['adresse' => 'Owendo'])->id, 'ordre' => 1]],
                'employes' => [['nom' => 'DUBOIS', 'prenom' => 'Marie', 'fonction' => null]],
                'objets' => [],
            ]))
            ->assertOk()
            ->assertJsonPath('data.reference', $originalReference)
            ->assertJsonPath('data.statut', Demande::STATUT_BROUILLON)
            ->assertJsonPath('data.motif', 'Mission modifiée')
            ->assertJsonPath('data.destinations.0.entite_geographique.adresse', 'Owendo');

        $demande->refresh();
        $this->assertSame($originalReference, $demande->reference);
        $this->assertSame(Demande::STATUT_BROUILLON, $demande->statut);
        $this->assertSame(1, $demande->destinations()->count());
        $this->assertSame(1, $demande->employes()->count());
        $this->assertSame(0, $demande->objets()->count());
    }

    public function test_submit_draft_changes_its_status_and_records_history(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['submit_demande']);
        $demande = $this->makeDemande($user);

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/soumission")
            ->assertOk()
            ->assertJsonPath('message', 'Demande soumise avec succès.')
            ->assertJsonPath('data.statut', Demande::STATUT_EN_ATTENTE);

        $this->assertDatabaseHas('demande_historiques', [
            'demande_id' => $demande->id,
            'ancien_statut' => Demande::STATUT_BROUILLON,
            'nouveau_statut' => Demande::STATUT_EN_ATTENTE,
            'utilisateur_id' => $user->id,
        ]);
    }

    public function test_submit_draft_returns_409_when_required_children_are_missing(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['submit_demande']);
        $demande = Demande::factory()
            ->for($user, 'demandeur')
            ->for(Direction::factory())
            ->create();

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/soumission")
            ->assertConflict()
            ->assertJsonPath('message', 'La demande ne contient pas toutes les données obligatoires.');

        $this->assertSame(Demande::STATUT_BROUILLON, $demande->fresh()->statut);
    }

    public function test_update_draft_returns_409_after_submission(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['update_demande']);
        $demande = $this->makeDemande($user, ['statut' => Demande::STATUT_EN_ATTENTE]);

        $this->withToken($token)
            ->putJson("/api/demandes/{$demande->id}", $this->validPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Seule une demande en brouillon peut être modifiée.');
    }

    public function test_other_standard_user_cannot_modify_another_users_demande(): void
    {
        ['user' => $owner] = $this->userWithPermissions(['update_demande']);
        ['token' => $otherToken] = $this->userWithPermissions(['update_demande']);
        $demande = $this->makeDemande($owner);

        $this->withToken($otherToken)
            ->putJson("/api/demandes/{$demande->id}", $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous n’avez pas la permission d’effectuer cette action.');
    }

    public function test_cancel_demande_records_its_reason_and_status_transition(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['cancel_demande']);
        $demande = $this->makeDemande($user, ['statut' => Demande::STATUT_EN_ATTENTE]);

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/annulation", ['motif' => 'Mission annulée par la direction.'])
            ->assertOk()
            ->assertJsonPath('data.statut', Demande::STATUT_ANNULEE);

        $this->assertDatabaseHas('demande_historiques', [
            'demande_id' => $demande->id,
            'ancien_statut' => Demande::STATUT_EN_ATTENTE,
            'nouveau_statut' => Demande::STATUT_ANNULEE,
            'utilisateur_id' => $user->id,
            'commentaire' => 'Mission annulée par la direction.',
        ]);
    }

    public function test_cancel_demande_requires_a_reason(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['cancel_demande']);
        $demande = $this->makeDemande($user);

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/annulation", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motif.0', 'Le champ motif est obligatoire.');
    }

    public function test_cancel_demande_returns_409_when_it_is_finished(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['cancel_demande']);
        $demande = $this->makeDemande($user, ['statut' => Demande::STATUT_TERMINEE]);

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/annulation", ['motif' => 'Trop tard'])
            ->assertConflict()
            ->assertJsonPath('message', 'Une demande terminée ne peut pas être annulée.');
    }

    public function test_modification_workflow_preserves_the_validated_demande_until_revalidation(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['update_demande', 'submit_demande']);
        $demande = $this->makeDemande($user, [
            'statut' => Demande::STATUT_VALIDEE,
            'motif' => 'Mission validée',
        ]);
        $originalReference = $demande->reference;

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/modification")
            ->assertOk()
            ->assertJsonPath('data.statut', Demande::STATUT_VALIDEE);

        $this->assertDatabaseHas('demande_modifications', [
            'demande_id' => $demande->id,
            'motif' => 'Mission validée',
            'submitted_at' => null,
        ]);

        $this->withToken($token)
            ->putJson("/api/demandes/{$demande->id}/modification", $this->validPayload([
                'motif' => 'Mission proposée',
                'commentaire' => 'Nouvel itinéraire',
            ]))
            ->assertOk()
            ->assertJsonPath('data.statut', Demande::STATUT_VALIDEE);

        $demande->refresh();
        $this->assertSame('Mission validée', $demande->motif);
        $this->assertSame(Demande::STATUT_VALIDEE, $demande->statut);
        $this->assertSame('Mission proposée', $demande->modification()->value('motif'));

        $this->withToken($token)
            ->postJson("/api/demandes/{$demande->id}/modification/soumission")
            ->assertOk()
            ->assertJsonPath('data.reference', $originalReference)
            ->assertJsonPath('data.statut', Demande::STATUT_REVALIDATION_REQUISE);

        $this->assertDatabaseHas('demande_historiques', [
            'demande_id' => $demande->id,
            'ancien_statut' => Demande::STATUT_VALIDEE,
            'nouveau_statut' => Demande::STATUT_REVALIDATION_REQUISE,
            'utilisateur_id' => $user->id,
        ]);
        $this->assertNotNull($demande->modification()->value('submitted_at'));
    }

    public function test_update_modification_returns_409_when_no_modification_is_open(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['update_demande']);
        $demande = $this->makeDemande($user, ['statut' => Demande::STATUT_VALIDEE]);

        $this->withToken($token)
            ->putJson("/api/demandes/{$demande->id}/modification", $this->validPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Aucune modification en cours ne correspond à cette demande.');
    }

    public function test_new_references_are_required_and_unknown_ids_return_422(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $payload = $this->validPayload();
        unset($payload['type_sortie_id'], $payload['adresse_depart_id']);

        $this->withToken($token)->postJson('/api/demandes', $payload)->assertUnprocessable()->assertJsonValidationErrors(['type_sortie_id', 'adresse_depart_id']);
        $this->withToken($token)->postJson('/api/demandes', $this->validPayload([
            'type_sortie_id' => 999999,
            'adresse_depart_id' => 999999,
            'adresse_retour_id' => 999999,
            'destinations' => [['entite_geographique_id' => 999999, 'ordre' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['type_sortie_id', 'adresse_depart_id', 'adresse_retour_id', 'destinations.0.entite_geographique_id']);
        $this->assertDatabaseCount('demandes', 0);
    }

    public function test_departure_return_and_ordered_destinations_are_independent_without_gps(): void
    {
        ['token' => $token] = $this->userWithPermissions(['create_demande']);
        $depart = EntiteGeographique::factory()->create();
        $retour = EntiteGeographique::factory()->create();
        $this->withToken($token)->postJson('/api/demandes', $this->validPayload([
            'adresse_depart_id' => $depart->id,
            'adresse_retour_id' => $retour->id,
            'destinations' => [
                ['entite_geographique_id' => $retour->id, 'ordre' => 2],
                ['entite_geographique_id' => $depart->id, 'ordre' => 1],
            ],
        ]))->assertCreated()->assertJsonPath('data.adresse_depart.id', $depart->id)->assertJsonPath('data.adresse_retour.id', $retour->id)->assertJsonPath('data.destinations.0.ordre', 1)->assertJsonPath('data.destinations.0.entite_geographique.latitude', null)->assertJsonMissingPath('data.destinations.0.libelle');
        $this->assertDatabaseHas('demandes', ['adresse_depart_id' => $depart->id, 'adresse_retour_id' => $retour->id]);
    }

    public function test_type_filter_preserves_pagination_and_ownership(): void
    {
        ['user' => $user, 'token' => $token] = $this->userWithPermissions(['read_demande']);
        $type = TypeSortie::factory()->create();
        $first = $this->makeDemande($user, ['type_sortie_id' => $type->id]);
        $this->makeDemande($user);
        $this->makeDemande(attributes: ['type_sortie_id' => $type->id]);

        $this->withToken($token)->getJson("/api/demandes?type_sortie_id={$type->id}&per_page=1")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.id', $first->id);
    }

    /**
     * @param  array<string, mixed>|Direction|null  $direction
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array|Direction|null $direction = null, array $overrides = []): array
    {
        if (is_array($direction)) {
            $overrides = $direction;
            $direction = null;
        }

        $direction ??= Direction::factory()->create();

        return [
            'direction_id' => $direction->id,
            'type_sortie_id' => TypeSortie::factory()->create()->id,
            'adresse_depart_id' => EntiteGeographique::factory()->create()->id,
            'adresse_retour_id' => null,
            'date_depart' => '2026-10-05',
            'heure_depart' => '08:00:00',
            'heure_retour' => '16:30:00',
            'motif' => 'Mission de service',
            'commentaire' => 'Prévoir un véhicule adapté.',
            'destinations' => [
                ['entite_geographique_id' => EntiteGeographique::factory()->create(['adresse' => 'Centre-ville de Libreville'])->id, 'ordre' => 1],
                ['entite_geographique_id' => EntiteGeographique::factory()->create(['adresse' => 'Nkok'])->id, 'ordre' => 2],
            ],
            'employes' => [
                ['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Chef de service'],
                ['nom' => 'DUBOIS', 'prenom' => 'Marie', 'fonction' => null],
            ],
            'objets' => [
                ['designation' => 'Documents administratifs', 'quantite' => 2, 'description' => 'Dossiers à déposer'],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDemande(?User $demandeur = null, array $attributes = [], ?Direction $direction = null): Demande
    {
        $demande = Demande::factory()
            ->for($demandeur ?? User::factory(), 'demandeur')
            ->for($direction ?? Direction::factory())
            ->create($attributes);

        $demande->destinations()->create(['entite_geographique_id' => EntiteGeographique::factory()->create(['adresse' => 'Libreville'])->id, 'ordre' => 1]);
        $demande->employes()->create(['nom' => 'MBOUROU', 'prenom' => 'Jean', 'fonction' => 'Chef de service']);
        $demande->objets()->create(['designation' => 'Documents administratifs', 'quantite' => 2, 'description' => 'Dossiers à déposer']);

        return $demande;
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array{token: string, user: User}
     */
    private function userWithPermissions(array $permissionNames, bool $superAdmin = false): array
    {
        $permissions = collect($permissionNames)
            ->map(fn (string $permissionName): Permission => Permission::query()->firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web'],
                ['libelle' => $permissionName],
            ));
        if ($superAdmin) {
            $this->seed(ParametrageSecuritySeeder::class);
            $role = Role::query()->where('system_key', 'super_admin')->firstOrFail();
        } else {
            $role = Role::factory()->create();
        }
        $role->givePermissionTo($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);

        return [
            'token' => $user->createToken('test-token')->plainTextToken,
            'user' => $user,
        ];
    }
}
