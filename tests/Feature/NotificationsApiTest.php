<?php

namespace Tests\Feature;

use App\Models\Chauffeur;
use App\Models\Demande;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\TreatmentNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Les tests Notifications exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new \RuntimeException('Le serveur n’a pas sélectionné aninf_test.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_notifications_are_authenticated_personal_and_paginated(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $recipient = $this->userWith();
        $otherUser = $this->userWith();
        $recipient->notify(new TreatmentNotification('COURSE_DEMARREE', 'Course démarrée.', 51, 91, '16-11-26-0001'));
        $recipient->notify(new TreatmentNotification('COURSE_TERMINEE', 'Course terminée.', 51, 91, '16-11-26-0001'));
        $otherUser->notify(new TreatmentNotification('COURSE_ASSIGNEE', 'Une Course vous a été affectée.', 51));
        $this->authenticate($recipient);

        $response = $this->getJson('/api/notifications?non_lues=true&per_page=1')->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
        $notification = $response->json('data.0');
        $this->assertSame(['id', 'evenement', 'message', 'course_id', 'demande_id', 'reference_demande', 'created_at', 'read_at'], array_keys($notification));
        $this->assertSame(1, $otherUser->unreadNotifications()->count());
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 2);
    }

    public function test_a_user_can_mark_only_their_notifications_as_read(): void
    {
        $recipient = $this->userWith();
        $otherUser = $this->userWith();
        $first = $this->notificationFor($recipient, 'DEMANDE_VALIDEE');
        $this->notificationFor($recipient, 'COURSE_DEMARREE');
        $otherNotification = $this->notificationFor($otherUser, 'COURSE_TERMINEE');
        $this->authenticate($recipient);

        $markedRead = $this->patchJson('/api/notifications/'.$first->id.'/read')->assertOk()
            ->assertJsonPath('data.id', $first->id);
        $this->assertIsString($markedRead->json('data.read_at'));
        $this->patchJson('/api/notifications/'.$first->id.'/read')->assertOk();
        $this->patchJson('/api/notifications/'.$otherNotification->id.'/read')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.marquees_lues', 1);
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.marquees_lues', 0);

        $this->assertSame(0, $recipient->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $otherUser->fresh()->unreadNotifications()->count());
    }

    public function test_notification_listing_validates_pagination_and_requires_password_change_first(): void
    {
        $user = $this->userWith();
        $this->authenticate($user);
        $this->getJson('/api/notifications?per_page=101')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->getJson('/api/notifications?unexpected=1')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $user->forceFill(['must_change_password' => true])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/notifications/unread-count')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_treatment_transitions_create_personal_notifications_once(): void
    {
        $this->travelTo('2026-11-12 07:00:00 UTC');
        $requester = $this->userWith();
        $dag = $this->userWith(['create_course', 'update_course', 'confirm_course']);
        $driver = $this->userWith(['start_course', 'finish_course']);
        $chauffeur = Chauffeur::factory()->create(['user_id' => $driver->id, 'statut' => 'ACTIF']);
        $vehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $demande = Demande::factory()->create(['demandeur_id' => $requester->id, 'statut' => Demande::STATUT_EN_ATTENTE]);
        $this->authenticate($dag);
        $course = $this->createReadyCourse($demande, $chauffeur, $vehicle);

        $confirmKey = (string) Str::uuid();
        $confirmation = ['version_attendue' => 1, 'motif' => 'Plan validé.'];
        $this->postJson('/api/courses/'.$course['id'], ['version_attendue' => 0, 'motif' => 'Version périmée.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertSame(0, $requester->notifications()->count());
        $this->assertSame(0, $driver->notifications()->count());
        $this->postJson('/api/courses/'.$course['id'], $confirmation, ['Idempotency-Key' => $confirmKey])->assertOk();
        $this->postJson('/api/courses/'.$course['id'], $confirmation, ['Idempotency-Key' => $confirmKey])->assertOk();
        $this->assertSame(['DEMANDE_VALIDEE'], $requester->notifications()->get()->pluck('data.evenement')->all());
        $this->assertSame(['COURSE_ASSIGNEE'], $driver->notifications()->get()->pluck('data.evenement')->all());

        $this->authenticate($driver);
        $startKey = (string) Str::uuid();
        $this->postJson('/api/courses/'.$course['id'].'/depart', ['version_attendue' => 2], ['Idempotency-Key' => $startKey])->assertOk();
        $this->postJson('/api/courses/'.$course['id'].'/depart', ['version_attendue' => 2], ['Idempotency-Key' => $startKey])->assertOk();
        $this->travelTo('2026-11-12 10:00:00 UTC');
        $finishKey = (string) Str::uuid();
        $finishPayload = [
            'version_attendue' => 3,
            'fin_reelle' => '2026-11-12T09:00:00+00:00',
            'chauffeur_libere_a' => '2026-11-12T09:45:00+00:00',
            'vehicule_libere_a' => '2026-11-12T09:55:00+00:00',
        ];
        $this->postJson('/api/courses/'.$course['id'].'/terminer', $finishPayload, ['Idempotency-Key' => $finishKey])->assertOk();
        $this->postJson('/api/courses/'.$course['id'].'/terminer', $finishPayload, ['Idempotency-Key' => $finishKey])->assertOk();

        $requesterEvents = $requester->notifications()->get()->pluck('data.evenement')->sort()->values()->all();
        $this->assertSame(['COURSE_DEMARREE', 'COURSE_TERMINEE', 'DEMANDE_VALIDEE'], $requesterEvents);
        $this->assertSame(1, $driver->notifications()->count());
        $this->assertSame(4, $requester->notifications()->count() + $driver->notifications()->count());
        $this->assertSame(3, DB::table('course_commands')->whereIn('command_key', [$confirmKey, $startKey, $finishKey])->count());

        $this->authenticate($requester);
        $listed = $this->getJson('/api/notifications?non_lues=true')->assertOk()->assertJsonCount(3, 'data');
        $this->assertContains('DEMANDE_VALIDEE', array_column($listed->json('data'), 'evenement'));
        $this->assertSame($demande->id, collect($listed->json('data'))->firstWhere('evenement', 'DEMANDE_VALIDEE')['demande_id']);
        $this->assertSame($demande->reference, collect($listed->json('data'))->firstWhere('evenement', 'DEMANDE_VALIDEE')['reference_demande']);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 3);
    }

    public function test_openapi_and_routes_expose_the_notifications_contract_without_rbac_permissions(): void
    {
        $contract = Yaml::parseFile(base_path('openapi.yaml'));
        $expected = [
            'get /api/notifications' => 'notificationList',
            'get /api/notifications/unread-count' => 'notificationUnreadCount',
            'patch /api/notifications/read-all' => 'notificationMarkAllRead',
            'patch /api/notifications/{id}/read' => 'notificationMarkRead',
        ];
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (($route->defaults['notification_api'] ?? false) !== true) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[strtolower($method).' /'.$route->uri()] = $route->gatherMiddleware();
            }
        }

        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $operation => $operationId) {
            [$method, $path] = explode(' ', $operation, 2);
            $definition = $contract['paths'][$path][$method];
            $this->assertSame($operationId, $definition['operationId']);
            $this->assertSame('implemented', $definition['x-implementation-status']);
            $this->assertSame([], $definition['x-permissions']);
            $this->assertContains('auth:sanctum', $actual[$operation]);
            $this->assertContains('password.changed', $actual[$operation]);
            $this->assertFalse(collect($actual[$operation])->contains(fn ($middleware): bool => str_contains($middleware, 'permission:')));
        }
    }

    private function notificationFor(User $user, string $event): DatabaseNotification
    {
        $existingIds = $user->notifications()->pluck('id')->all();
        $user->notify(new TreatmentNotification($event, 'Notification de test.', 51, 91, '16-11-26-0001'));

        return $user->notifications()->whereNotIn('id', $existingIds)->firstOrFail();
    }

    private function userWith(array $permissions = []): User
    {
        $user = User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('notification-tests')->plainTextToken);
    }

    /** @return array<string, mixed> */
    private function createReadyCourse(Demande $demande, Chauffeur $chauffeur, Vehicule $vehicule): array
    {
        $course = $this->postJson('/api/courses', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->putJson('/api/courses/'.$course['id'], [
            'version_attendue' => 0,
            'demande_ids' => [$demande->id],
            'chauffeur_id' => $chauffeur->id,
            'vehicule_id' => $vehicule->id,
            'plan' => [
                'occupation_debut_prevue' => '2026-11-12T07:30:00+01:00',
                'occupation_fin_prevue' => '2026-11-12T17:00:00+01:00',
                'chargement_confirme' => true,
                'arrets' => [['ordre' => 1, 'lieu' => 'TEST notification', 'employes' => [], 'objets' => []]],
            ],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $course;
    }
}
