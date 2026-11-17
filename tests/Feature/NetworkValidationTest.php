<?php

namespace Tests\Feature;

use App\Models\Chauffeur;
use App\Models\Demande;
use App\Models\User;
use App\Models\Vehicule;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\NetworkEnvironment;

class NetworkValidationTest extends TestCase
{
    private static ?NetworkEnvironment $network = null;

    protected function setUp(): void
    {
        if (getenv('ANINF_NETWORK_VALIDATION') !== '1') {
            $this->markTestSkipped('Utiliser exclusivement phpunit.network.xml pour lancer les processus isoles.');
        }
        self::$network ??= new NetworkEnvironment;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$network !== null) {
            self::$network->evidence['logs_without_secrets'] = self::$network->logsContainNoSecrets();
            self::$network->saveEvidence();
            self::$network->stop();
        }
    }

    private function expectHttpCode(int $expected, array $response, string $scenario): void
    {
        $responseCode = $response['json']['code'] ?? 'NO_ERROR_CODE';
        $this->assertTrue($response['status'] === $expected, $scenario.' : HTTP attendu '.$expected.', recu '.$response['status'].' ('.$responseCode.')');
    }

    private function key(): string
    {
        return Str::uuid()->toString();
    }

    /** @return array{user: User, token: string} */
    private function networkActor(string $suffix, array $permissions): array
    {
        $network = self::$network;
        $password = bin2hex(random_bytes(12));
        $user = User::factory()->create([
            'email' => 'network-'.$suffix.'-'.$network->runId.'@example.test',
            'password' => Hash::make($password),
            'statut' => 'ACTIF',
            'must_change_password' => false,
        ]);
        $user->givePermissionTo($permissions);
        $accountKey = 'network_'.$suffix;
        $network->accounts[$accountKey] = ['id' => $user->id, 'email' => $user->email, 'password' => $password, 'token' => null];
        $token = $network->login($accountKey)['json']['data']['token'];

        return ['user' => $user, 'token' => $token];
    }

    private function direction(string $suffix): array
    {
        $network = self::$network;
        $response = $network->request('POST', '/api/directions', ['nom' => 'TEST NET '.$suffix, 'code' => 'TEST_NET_'.strtoupper($suffix).'_'.$network->runId], $network->activeAdminToken(), ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(201, $response, 'Creation direction isolee');

        return $response['json']['data'];
    }

    public function test_real_http_transport_authentication_cors_parametrage_and_demande(): void
    {
        $network = self::$network;
        $cases = [];
        $login = $network->login('user');
        $this->expectHttpCode(200, $login, 'Login TCP');
        $token = $login['json']['data']['token'];
        $cases['login'] = $login['status'];
        $profile = $network->request('GET', '/api/me', token: $token);
        $this->expectHttpCode(200, $profile, 'Profil');
        $this->assertSame($network->accounts['user']['id'], $profile['json']['data']['id']);
        $cases['profile'] = $profile['status'];
        $allowed = $network->request('GET', '/api/directions', token: $token, headers: ['Origin' => 'https://frontend.validation.invalid']);
        $this->expectHttpCode(200, $allowed, 'Lecture permissionnee');
        $this->assertTrue(in_array($allowed['headers']['access-control-allow-origin'] ?? null, ['*', 'https://frontend.validation.invalid'], true));
        $cases['allowed'] = $allowed['status'];
        $forbidden = $network->request('POST', '/api/directions', ['nom' => 'Interdit', 'code' => 'INTERDIT'], $token, ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(403, $forbidden, 'Acces administratif interdit');
        $cases['forbidden'] = $forbidden['status'];
        $validation = $network->request('POST', '/api/directions', [], $network->activeAdminToken(), ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(422, $validation, 'Validation');
        $cases['validation'] = $validation['status'];
        $direction = $this->direction('transport');
        $cases['parametrage_create'] = 201;
        $update = $network->request('PUT', '/api/directions/'.$direction['id'], ['nom' => 'TEST NET corrige', 'version_attendue' => 0], $network->activeAdminToken(), ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(200, $update, 'Modification Parametrage');
        $conflict = $network->request('PUT', '/api/directions/'.$direction['id'], ['nom' => 'Perime', 'version_attendue' => 0], $network->activeAdminToken(), ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(409, $conflict, 'Conflit de version');
        $this->assertSame('VERSION_CONFLICT', $conflict['json']['code'] ?? null);
        $cases['parametrage_update'] = $update['status'];
        $cases['version_conflict'] = $conflict['status'];
        $payload = ['direction_id' => $network->fixtures['direction'], 'type_sortie_id' => $network->fixtures['type'], 'adresse_depart_id' => $network->fixtures['lieu'], 'date_depart' => gmdate('Y-m-d', time() + 172800), 'heure_depart' => '08:00:00', 'motif' => 'TEST NET transport reel', 'destinations' => [['entite_geographique_id' => $network->fixtures['lieu'], 'ordre' => 1]], 'employes' => [['nom' => 'TEST NET', 'prenom' => 'Transport']], 'objets' => null];
        $created = $network->request('POST', '/api/demandes', $payload, $token);
        $this->expectHttpCode(201, $created, 'Creation Demande TCP');
        $read = $network->request('GET', '/api/demandes/'.$created['json']['data']['id'], token: $token);
        $this->expectHttpCode(200, $read, 'Consultation Demande TCP');
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM demandes WHERE id = ? AND demandeur_id = ?', [$created['json']['data']['id'], $network->accounts['user']['id']]));
        $cases['demande_create'] = $created['status'];
        $cases['demande_read'] = $read['status'];
        $preflight = $network->request('OPTIONS', '/api/directions', headers: ['Origin' => 'https://frontend.validation.invalid', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type,idempotency-key']);
        $this->expectHttpCode(204, $preflight, 'Preflight CORS');
        $this->assertTrue(str_contains(strtolower($preflight['headers']['access-control-allow-headers'] ?? ''), 'authorization'));
        $cases['cors_preflight'] = $preflight['status'];
        $invalid = $network->request('GET', '/api/me', token: 'invalid-validation-token');
        $this->expectHttpCode(401, $invalid, 'Jeton invalide');
        $cases['invalid_token'] = $invalid['status'];
        $logout = $network->request('POST', '/api/logout', token: $token);
        $this->expectHttpCode(200, $logout, 'Logout TCP');
        $revoked = $network->request('GET', '/api/me', token: $token);
        $this->expectHttpCode(401, $revoked, 'Jeton revoque');
        $cases['logout'] = $logout['status'];
        $cases['revoked_token'] = $revoked['status'];
        $this->assertTrue($network->logsContainNoSecrets(), 'Aucun secret ne doit etre present dans les logs du serveur prive.');
        $network->evidence['http'] = ['passed' => true, 'scenarios' => $cases, 'scenario_count' => count($cases), 'cors' => ['configured_paths' => config('cors.paths'), 'credentials' => config('cors.supports_credentials'), 'mode_tested' => 'Bearer sans cookies']];
        $network->saveEvidence();
    }

    private function simultaneous(array $race, string $name): void
    {
        $this->assertSame(2, $race['simultaneous_mysql_waiters'], $name.' : deux connexions distinctes doivent attendre simultanement la garde MySQL du scenario.');
        $this->assertCount(2, array_unique($race['mysql_connections']));
        $this->assertCount(2, array_unique($race['worker_pids']));
        $this->assertNotContains(null, $race['worker_pids']);
    }

    public function test_two_real_processes_cannot_overwrite_the_same_expected_version(): void
    {
        $network = self::$network;
        $direction = $this->direction('versions');
        $keys = [$this->key(), $this->key()];
        $requests = [];
        foreach (['A', 'B'] as $index => $label) {
            $requests[] = ['method' => 'PUT', 'path' => '/api/directions/'.$direction['id'], 'body' => ['nom' => 'TEST NET version '.$label, 'version_attendue' => 0], 'token' => $network->activeAdminToken(), 'key' => $keys[$index]];
        }
        $race = $network->race($requests);
        $this->simultaneous($race, 'Version attendue');
        $statuses = array_column($race['responses'], 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, (int) $network->scalar('SELECT lock_version FROM directions WHERE id = ?', [$direction['id']]));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM organisation_audits WHERE object_type = 'direction' AND object_id = ? AND action = 'update'", [$direction['id']]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM organisation_commands WHERE command_key IN (?, ?)', $keys));
        foreach ($race['responses'] as $response) {
            if ($response['status'] === 200) {
                $this->assertSame($response['json']['data']['nom'], $network->scalar('SELECT nom FROM directions WHERE id = ?', [$direction['id']]));
            } else {
                $this->assertSame('VERSION_CONFLICT', $response['json']['code'] ?? null);
            }
        }
        $network->evidence['concurrency']['version'] = ['passed' => true, 'statuses' => $statuses, 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'successful_mutations' => 1, 'successful_audits' => 1, 'commands' => 1, 'final_version' => 1];
        $network->saveEvidence();
    }

    public function test_two_real_processes_with_one_idempotency_key_have_one_effect_and_one_audit(): void
    {
        $network = self::$network;
        $key = $this->key();
        $code = 'TEST_NET_IDEMP_'.$network->runId;
        $request = ['method' => 'POST', 'path' => '/api/directions', 'body' => ['nom' => 'TEST NET idempotence', 'code' => $code], 'token' => $network->activeAdminToken(), 'key' => $key];
        $race = $network->race([$request, $request]);
        $this->simultaneous($race, 'Idempotence');
        $this->assertSame([201, 201], array_column($race['responses'], 'status'));
        $this->assertTrue($this->canonical($race['responses'][0]['json']) === $this->canonical($race['responses'][1]['json']), 'Les objets JSON doivent etre identiques independamment de l’ordre des cles.');
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM directions WHERE code = ?', [$code]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM organisation_commands WHERE command_key = ?', [$key]));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM organisation_audits WHERE command_key = ? AND action = 'create'", [$key]));
        $network->evidence['concurrency']['idempotence'] = ['passed' => true, 'statuses' => [201, 201], 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'objects' => 1, 'commands' => 1, 'successful_audits' => 1, 'identical_responses' => true];
        $network->saveEvidence();
    }

    public function test_two_real_processes_preserve_the_last_effective_administrator(): void
    {
        $network = self::$network;
        foreach (['a', 'b'] as $name) {
            if ($network->accounts[$name]['token'] === null) {
                $network->login($name);
            }
        }
        $this->assertSame(2, (int) $network->scalar("SELECT COUNT(*) FROM users u JOIN model_has_roles m ON m.model_id = u.id JOIN roles r ON r.id = m.role_id WHERE u.statut = 'ACTIF' AND r.statut = 'ACTIF' AND r.system_key = 'super_admin'"));
        $keys = [$this->key(), $this->key()];
        $requests = [];
        foreach (['a', 'b'] as $index => $name) {
            $account = $network->accounts[$name];
            $requests[] = ['method' => 'PATCH', 'path' => '/api/users/'.$account['id'].'/deactivate', 'body' => ['version_attendue' => 0, 'motif' => 'TEST NET concurrence administrateurs'], 'token' => $account['token'], 'key' => $keys[$index]];
        }
        $race = $network->race($requests);
        $this->simultaneous($race, 'Dernier administrateur');
        $statuses = array_column($race['responses'], 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM users u JOIN model_has_roles m ON m.model_id = u.id JOIN roles r ON r.id = m.role_id WHERE u.statut = 'ACTIF' AND r.statut = 'ACTIF' AND r.system_key = 'super_admin'"));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM organisation_commands WHERE command_key IN (?, ?)', $keys));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM organisation_audits WHERE command_key IN (?, ?) AND action = 'deactivate_user'", $keys));
        $ids = [$network->accounts['a']['id'], $network->accounts['b']['id']];
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM organisation_audits WHERE object_type = 'user' AND object_id IN (?, ?) AND action = 'refus'", $ids));
        foreach ($race['responses'] as $index => $response) {
            $name = ['a', 'b'][$index];
            $profile = $network->request('GET', '/api/me', token: $network->accounts[$name]['token']);
            $this->expectHttpCode($response['status'] === 200 ? 401 : 200, $profile, 'Etat des jetons apres concurrence');
            if ($response['status'] === 409) {
                $this->assertSame('LAST_EFFECTIVE_ADMIN', $response['json']['code'] ?? null);
                $this->assertSame(0, (int) $network->scalar('SELECT lock_version FROM users WHERE id = ?', [$network->accounts[$name]['id']]));
            }
        }
        $network->evidence['concurrency']['last_admin'] = ['passed' => true, 'statuses' => $statuses, 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'remaining_active_admins' => 1, 'successful_mutations' => 1, 'commands' => 1, 'successful_audits' => 1, 'refusal_audits' => 1, 'token_states_verified' => true];
        $network->saveEvidence();
    }

    public function test_two_real_processes_cannot_create_resource_unavailability_from_the_same_resource_version(): void
    {
        $network = self::$network;
        app(PermissionSeeder::class)->run();
        $actor = $this->networkActor('transport-availability-create', ['manage_resource_availability']);
        $administrator = $actor['user'];
        $chauffeur = Chauffeur::factory()->create(['user_id' => $administrator->id, 'statut' => 'ACTIF']);
        $token = $actor['token'];
        $keys = [$this->key(), $this->key()];
        $start = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $requests = [];
        foreach (['A', 'B'] as $index => $label) {
            $requests[] = [
                'method' => 'POST',
                'path' => '/api/transport/indisponibilites',
                'body' => [
                    'type_ressource' => 'CHAUFFEUR',
                    'ressource_id' => $chauffeur->id,
                    'version_ressource_attendue' => 0,
                    'debut_a' => $start,
                    'fin_estimee_a' => null,
                    'motif' => 'TEST NET indisponibilite '.$label,
                ],
                'token' => $token,
                'key' => $keys[$index],
            ];
        }

        $race = $network->race($requests, 'course_guards');
        $this->simultaneous($race, 'Création simultanée d’indisponibilité');
        $statuses = array_column($race['responses'], 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, (int) $network->scalar('SELECT lock_version FROM chauffeurs WHERE id = ?', [$chauffeur->id]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM indisponibilite_ressources WHERE chauffeur_id = ?', [$chauffeur->id]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM transport_resource_events WHERE action = \'created\' AND command_key IN (?, ?)', $keys));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM course_commands WHERE command_key IN (?, ?)', $keys));
        foreach ($race['responses'] as $response) {
            if ($response['status'] === 409) {
                $this->assertSame('VERSION_CONFLICT', $response['json']['code'] ?? null);
            }
        }
        $network->evidence['concurrency']['transport_resource_version'] = ['passed' => true, 'statuses' => $statuses, 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'periods' => 1, 'resource_version' => 1, 'events' => 1, 'commands' => 1];
        $network->saveEvidence();
    }

    public function test_two_real_processes_cannot_correct_the_same_resource_period_version(): void
    {
        $network = self::$network;
        app(PermissionSeeder::class)->run();
        $actor = $this->networkActor('transport-availability-update', ['manage_resource_availability']);
        $administrator = $actor['user'];
        $chauffeur = Chauffeur::factory()->create(['user_id' => $administrator->id, 'statut' => 'ACTIF']);
        $token = $actor['token'];
        $start = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $created = $network->request('POST', '/api/transport/indisponibilites', [
            'type_ressource' => 'CHAUFFEUR',
            'ressource_id' => $chauffeur->id,
            'version_ressource_attendue' => 0,
            'debut_a' => $start,
            'fin_estimee_a' => null,
            'motif' => 'TEST NET periode a corriger',
        ], $token, ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(201, $created, 'Création préalable d’indisponibilité');
        $periodId = (int) $created['json']['data']['id'];
        $keys = [$this->key(), $this->key()];
        $requests = [];
        foreach (['A', 'B'] as $index => $label) {
            $requests[] = [
                'method' => 'PUT',
                'path' => '/api/transport/indisponibilites/'.$periodId,
                'body' => [
                    'version_attendue' => 0,
                    'debut_a' => $start,
                    'fin_estimee_a' => null,
                    'motif' => 'TEST NET correction '.$label,
                    'motif_correction' => 'TEST NET concurrence de correction',
                ],
                'token' => $token,
                'key' => $keys[$index],
            ];
        }

        $race = $network->race($requests, 'course_guards');
        $this->simultaneous($race, 'Correction simultanée d’une période');
        $statuses = array_column($race['responses'], 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, (int) $network->scalar('SELECT lock_version FROM indisponibilite_ressources WHERE id = ?', [$periodId]));
        $this->assertSame(2, (int) $network->scalar('SELECT lock_version FROM chauffeurs WHERE id = ?', [$chauffeur->id]));
        $this->assertSame(2, (int) $network->scalar('SELECT COUNT(*) FROM transport_resource_events WHERE indisponibilite_ressource_id = ?', [$periodId]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM transport_resource_events WHERE indisponibilite_ressource_id = ? AND action = \'corrected\' AND command_key IN (?, ?)', [$periodId, ...$keys]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM course_commands WHERE command_key IN (?, ?)', $keys));
        foreach ($race['responses'] as $response) {
            if ($response['status'] === 409) {
                $this->assertSame('VERSION_CONFLICT', $response['json']['code'] ?? null);
            }
        }
        $network->evidence['concurrency']['transport_period_version'] = ['passed' => true, 'statuses' => $statuses, 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'period_version' => 1, 'resource_version' => 2, 'events' => 2, 'commands_in_race' => 1];
        $network->saveEvidence();
    }

    public function test_two_real_processes_replay_one_transport_signalement_command(): void
    {
        $network = self::$network;
        app(PermissionSeeder::class)->run();
        $actor = $this->networkActor('transport-signalement', ['report_course_incident']);
        $reporter = $actor['user'];
        $chauffeur = Chauffeur::factory()->create(['user_id' => $reporter->id, 'statut' => 'ACTIF']);
        $token = $actor['token'];
        $key = $this->key();
        $request = [
            'method' => 'POST',
            'path' => '/api/transport/signalements',
            'body' => [
                'type' => 'INDISPONIBILITE',
                'type_ressource' => 'CHAUFFEUR',
                'ressource_id' => $chauffeur->id,
                'details' => 'TEST NET signalement concurrent idempotent',
            ],
            'token' => $token,
            'key' => $key,
        ];

        $race = $network->race([$request, $request], 'course_guards');
        $this->simultaneous($race, 'Rejeu simultané de signalement Transport');
        $this->assertSame([201, 201], array_column($race['responses'], 'status'));
        $this->assertSame($this->canonical($race['responses'][0]['json']), $this->canonical($race['responses'][1]['json']));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM signalement_transports WHERE reported_by_id = ? AND chauffeur_id = ?', [$reporter->id, $chauffeur->id]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM course_commands WHERE actor_id = ? AND command_key = ?', [$reporter->id, $key]));
        $network->evidence['concurrency']['transport_idempotency'] = ['passed' => true, 'statuses' => [201, 201], 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'signalements' => 1, 'commands' => 1, 'identical_responses' => true];
        $network->saveEvidence();
    }

    public function test_two_real_processes_cannot_confirm_courses_that_overlap_on_a_vehicle(): void
    {
        $network = self::$network;
        app(PermissionSeeder::class)->run();
        $manager = $this->networkActor('course-conflict-manager', ['create_course', 'update_course', 'confirm_course']);
        $firstChauffeur = Chauffeur::factory()->create(['statut' => 'ACTIF']);
        $secondChauffeur = Chauffeur::factory()->create(['statut' => 'ACTIF']);
        $sharedVehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $token = $manager['token'];
        $firstCourse = $this->prepareCourse($network, $token, $firstChauffeur->id, $sharedVehicle->id);
        $secondCourse = $this->prepareCourse($network, $token, $secondChauffeur->id, $sharedVehicle->id);
        $keys = [$this->key(), $this->key()];
        $requests = [];
        foreach ([$firstCourse, $secondCourse] as $index => $course) {
            $requests[] = ['method' => 'POST', 'path' => '/api/courses/'.$course['id'], 'body' => ['version_attendue' => 1, 'motif' => 'TEST NET confirmation simultanee'], 'token' => $token, 'key' => $keys[$index]];
        }

        $race = $network->race($requests, 'course_guards');
        $this->simultaneous($race, 'Confirmation Course avec ressource commune');
        $statuses = array_column($race['responses'], 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM courses WHERE statut_execution = 'A_VENIR'"));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM course_events WHERE action = 'confirmed'"));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM demandes WHERE statut = 'VALIDEE'"));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM demandes WHERE statut = 'EN_ATTENTE'"));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM course_commands WHERE command_key IN (?, ?)', $keys));
        foreach ($race['responses'] as $response) {
            if ($response['status'] === 409) {
                $this->assertSame('RESOURCE_CONFLICT', $response['json']['code'] ?? null);
            }
        }
        $network->evidence['concurrency']['course_resource_conflict'] = ['passed' => true, 'statuses' => $statuses, 'waiters' => $race['simultaneous_mysql_waiters'], 'mysql_connections' => $race['mysql_connections'], 'worker_pids' => $race['worker_pids'], 'confirmed_courses' => 1, 'confirmed_demands' => 1, 'commands' => 1];
        $network->saveEvidence();
    }

    public function test_two_real_processes_start_once_and_replay_one_course_finish_command(): void
    {
        $network = self::$network;
        app(PermissionSeeder::class)->run();
        $manager = $this->networkActor('course-execution-manager', ['create_course', 'update_course', 'confirm_course']);
        $driverPassword = bin2hex(random_bytes(12));
        $driver = User::factory()->create(['email' => 'network-course-driver-'.$network->runId.'@example.test', 'password' => Hash::make($driverPassword), 'statut' => 'ACTIF', 'must_change_password' => false]);
        $driver->givePermissionTo(['start_course', 'finish_course']);
        $network->accounts['course_driver'] = ['id' => $driver->id, 'email' => $driver->email, 'password' => $driverPassword, 'token' => null];
        $driverToken = $network->login('course_driver')['json']['data']['token'];
        $chauffeur = Chauffeur::factory()->create(['user_id' => $driver->id, 'statut' => 'ACTIF']);
        $vehicle = Vehicule::factory()->create(['statut' => 'ACTIF']);
        $dagToken = $manager['token'];
        $course = $this->prepareCourse($network, $dagToken, $chauffeur->id, $vehicle->id);
        $confirmation = $network->request('POST', '/api/courses/'.$course['id'], ['version_attendue' => 1, 'motif' => 'TEST NET confirmation chauffeur'], $dagToken, ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(200, $confirmation, 'Confirmation avant depart concurrent');
        $startRequests = [];
        $startKeys = [$this->key(), $this->key()];
        foreach ($startKeys as $key) {
            $startRequests[] = ['method' => 'POST', 'path' => '/api/courses/'.$course['id'].'/depart', 'body' => ['version_attendue' => 2], 'token' => $driverToken, 'key' => $key];
        }

        $startRace = $network->race($startRequests, 'course_guards');
        $this->simultaneous($startRace, 'Depart Course concurrent');
        $startStatuses = array_column($startRace['responses'], 'status');
        sort($startStatuses);
        $this->assertSame([200, 409], $startStatuses);
        $this->assertSame('EN_COURS', $network->scalar('SELECT statut_execution FROM courses WHERE id = ?', [$course['id']]));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM course_events WHERE course_id = ? AND action = 'started'", [$course['id']]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM course_commands WHERE command_key IN (?, ?)', $startKeys));
        foreach ($startRace['responses'] as $response) {
            if ($response['status'] === 409) {
                $this->assertSame('VERSION_CONFLICT', $response['json']['code'] ?? null);
            }
        }

        sleep(2);
        $timestamp = gmdate('Y-m-d\TH:i:s+00:00');
        $finishKey = $this->key();
        $finishRequest = ['method' => 'POST', 'path' => '/api/courses/'.$course['id'].'/terminer', 'body' => ['version_attendue' => 3, 'fin_reelle' => $timestamp, 'chauffeur_libere_a' => $timestamp, 'vehicule_libere_a' => $timestamp], 'token' => $driverToken, 'key' => $finishKey];
        $finishRace = $network->race([$finishRequest, $finishRequest], 'course_guards');
        $this->simultaneous($finishRace, 'Rejeu idempotent de fin de Course');
        $this->assertSame([200, 200], array_column($finishRace['responses'], 'status'));
        $this->assertSame($this->canonical($finishRace['responses'][0]['json']), $this->canonical($finishRace['responses'][1]['json']));
        $this->assertSame('TERMINEE', $network->scalar('SELECT statut_execution FROM courses WHERE id = ?', [$course['id']]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM courses WHERE id = ? AND terminee_a IS NOT NULL AND chauffeur_libere_a >= terminee_a AND vehicule_libere_a >= terminee_a', [$course['id']]));
        $this->assertSame(1, (int) $network->scalar("SELECT COUNT(*) FROM course_events WHERE course_id = ? AND action = 'finished'", [$course['id']]));
        $this->assertSame(1, (int) $network->scalar('SELECT COUNT(*) FROM course_commands WHERE command_key = ?', [$finishKey]));
        $this->assertSame('VALIDEE', $network->scalar('SELECT statut FROM demandes WHERE id = ?', [$course['demande_id']]));
        $network->evidence['concurrency']['course_execution'] = ['passed' => true, 'start_statuses' => $startStatuses, 'start_waiters' => $startRace['simultaneous_mysql_waiters'], 'finish_statuses' => [200, 200], 'finish_waiters' => $finishRace['simultaneous_mysql_waiters'], 'worker_pids' => $finishRace['worker_pids'], 'start_events' => 1, 'finish_events' => 1, 'finish_commands' => 1, 'demand_status_after_finish' => 'VALIDEE'];
        $network->saveEvidence();
    }

    /** @return array{id: int, demande_id: int} */
    private function prepareCourse(NetworkEnvironment $network, string $token, int $chauffeurId, int $vehiculeId): array
    {
        $demande = Demande::factory()->create(['statut' => Demande::STATUT_EN_ATTENTE]);
        $created = $network->request('POST', '/api/courses', [], $token, ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(201, $created, 'Creation de preparation Course');
        $courseId = (int) $created['json']['data']['id'];
        $updated = $network->request('PUT', '/api/courses/'.$courseId, [
            'version_attendue' => 0,
            'demande_ids' => [$demande->id],
            'chauffeur_id' => $chauffeurId,
            'vehicule_id' => $vehiculeId,
            'plan' => [
                'occupation_debut_prevue' => '2026-11-12T07:30:00+01:00',
                'occupation_fin_prevue' => '2026-11-12T17:00:00+01:00',
                'chargement_confirme' => true,
                'arrets' => [['ordre' => 1, 'lieu' => 'TEST NET depart', 'employes' => [], 'objets' => []]],
            ],
        ], $token, ['Idempotency-Key' => $this->key()]);
        $this->expectHttpCode(200, $updated, 'Preparation Course complete');

        return ['id' => $courseId, 'demande_id' => $demande->id];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }

    public function test_real_http_reauthentication_limit_survives_failed_transactions(): void
    {
        $network = self::$network;
        $token = $network->activeAdminToken();
        $target = $network->accounts['user']['id'];
        $invalidPayload = ['version_attendue' => 0, 'password_generation' => 1, 'motif' => 'TEST NET champ inattendu', 'current_password' => 'invalid-network-password', 'champ_inattendu' => 'valeur'];
        $unknownField = $network->request('POST', '/api/users/'.$target.'/temporary-password/reveal', $invalidPayload, $token);
        $this->assertSame(422, $unknownField['status']);
        $this->assertSame('VALIDATION_ERROR', $unknownField['json']['code'] ?? null);

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $result = $network->request('POST', '/api/users/'.$target.'/temporary-password/reveal', ['version_attendue' => 0, 'password_generation' => 1, 'motif' => 'TEST NET refus reauthentification', 'current_password' => 'invalid-network-password'], $token);
            $statuses[] = $result['status'];
        }
        $retryAfter = (int) ($result['headers']['retry-after'] ?? 0);
        $this->assertSame('TOO_MANY_ATTEMPTS', $result['json']['code'] ?? null);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $network->evidence['http_reauthentication'] = ['statuses' => $statuses, 'retry_after' => $retryAfter, 'unknown_field_status' => $unknownField['status'], 'passed' => $statuses === [401, 401, 401, 401, 401, 429], 'cache_store' => config('cache.default')];
        $network->saveEvidence();
        $this->assertSame([401, 401, 401, 401, 401, 429], $statuses, 'Les tentatives doivent etre conservees apres rollback de la transaction administrative.');
    }

    public function test_existing_parametrage_suite_on_the_same_disposable_mysql_instance(): void
    {
        $network = self::$network;
        $environment = array_replace($network->env, ['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null']);
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '-c', 'phpunit.parametrage.xml'], $network->root, $environment);
        $process->setTimeout(300);
        $exit = $process->run();
        $output = $process->getOutput();
        $result = json_decode(trim($output), true);
        if (! is_array($result)) {
            preg_match('/OK \\((\\d+) tests, (\\d+) assertions\\)/', $output, $matches);
            $result = ['tests' => isset($matches[1]) ? (int) $matches[1] : null, 'assertions' => isset($matches[2]) ? (int) $matches[2] : null];
        }
        $network->evidence['parametrage_suite'] = ['exit_code' => $exit, 'tests' => $result['tests'] ?? null, 'assertions' => $result['assertions'] ?? null, 'failed' => $result['failed'] ?? 0, 'errors' => $result['errors'] ?? 0, 'failure_names' => array_column($result['failures'] ?? [], 'test'), 'error_names' => array_column($result['error_details'] ?? [], 'test')];
        if ($exit !== 0) {
            $diagnostic = $network->redactSensitiveText($output.$process->getErrorOutput());
            if (preg_match('/(?:ERRORS!|FAILURES!)(.*?)(?:Tests: \d+|Time:)/s', $diagnostic, $matches) === 1) {
                $network->evidence['parametrage_suite']['sanitized_diagnostic'] = mb_substr(trim($matches[1]), 0, 3000);
            }
            $network->evidence['parametrage_suite']['sanitized_errors'] = array_map(function (array $error) use ($network): array {
                $safe = [];
                foreach (['test', 'exception', 'class', 'message', 'file', 'line'] as $key) {
                    if (isset($error[$key]) && is_scalar($error[$key])) {
                        $safe[$key] = $network->redactSensitiveText((string) $error[$key]);
                    }
                }

                return $safe;
            }, $result['error_details'] ?? []);
        }
        $network->saveEvidence();
        $this->assertSame(0, $exit, 'La suite Parametrage doit rester verte sur le serveur MySQL prive ; aucun payload sensible de diagnostic expose.');
        $this->assertSame(257, $result['tests'] ?? null);
        $this->assertTrue($network->logsContainNoSecrets());
    }
}
