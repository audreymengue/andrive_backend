<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\TreatmentNotification;
use App\Services\FirebaseMessaging;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('mysql')]
class PushNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    private const OAUTH = 'https://oauth2.googleapis.com/token';

    private const FCM = 'https://fcm.googleapis.com/v1/projects/aninf-test/messages:send';

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aninf_test'
            || filled(config('database.connections.mysql.url')) || DB::selectOne('SELECT DATABASE() AS db')->db !== 'aninf_test') {
            throw new RuntimeException('Les tests push exigent exclusivement mysql/aninf_test, sans DB_URL.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fcm.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_device_endpoints_require_authentication_and_a_changed_password(): void
    {
        $uri = '/api/me/push-devices/'.Str::uuid();
        $payload = ['plateforme' => 'android', 'token' => 'qa-fcm:first'];
        $this->putJson($uri, $payload)->assertUnauthorized();
        $this->deleteJson($uri)->assertUnauthorized();

        $this->authenticate(User::factory()->create(['must_change_password' => true, 'statut' => 'ACTIF']));
        $this->putJson($uri, $payload)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->deleteJson($uri)->assertForbidden();
        $this->assertDatabaseMissing('push_devices', ['token_hash' => hash('sha256', $payload['token'])]);
    }

    public function test_device_input_is_validated_without_echoing_the_token(): void
    {
        $user = $this->user();
        $this->authenticate($user);
        $uri = '/api/me/push-devices/'.Str::uuid();
        $payload = ['plateforme' => 'web', 'token' => 'qa-fcm:private'];

        $this->putJson('/api/me/push-devices/invalid', $payload)->assertUnprocessable()->assertJsonValidationErrors('installation_id');
        $this->putJson($uri, [])->assertUnprocessable()->assertJsonValidationErrors(['plateforme', 'token']);
        $this->putJson($uri, ['plateforme' => 'desktop', 'token' => 'qa private token'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plateforme', 'token'])->assertDontSee('qa private token');
        $this->putJson($uri, [...$payload, 'user_id' => $user->id])->assertUnprocessable();
        $this->deleteJson($uri, ['token' => $payload['token']])->assertUnprocessable()->assertDontSee($payload['token']);
        $this->assertDatabaseMissing('push_devices', ['user_id' => $user->id]);
    }

    public function test_tokens_rotate_and_logout_revokes_only_the_current_session(): void
    {
        $owner = $this->user();
        $this->authenticate($owner);
        $device = $this->register('qa-fcm:initial');
        $uri = '/api/me/push-devices/'.$device->installation_id;
        $current = ['plateforme' => 'ios', 'token' => 'qa-fcm:rotated'];

        $this->putJson($uri, $current)->assertOk()->assertDontSee($current['token']);
        $this->putJson($uri, $current)->assertOk();
        $this->assertSame(1, PushDevice::query()->where('user_id', $owner->id)->count());
        $this->assertSame('ios', $device->fresh()->plateforme);
        $this->assertSame($current['token'], $device->fresh()->token);
        $this->assertNotSame($current['token'], DB::table('push_devices')->where('id', $device->id)->value('token'));
        $this->assertArrayNotHasKey('token', $device->toArray());
        $this->assertArrayNotHasKey('token_hash', $device->toArray());

        $this->authenticate($owner);
        $otherSessionDevice = $this->register('qa-fcm:second-session');
        $this->postJson('/api/logout')->assertOk();
        $this->assertModelMissing($otherSessionDevice);
        $this->assertModelExists($device);
    }

    public function test_switching_accounts_requires_the_matching_installation_and_token_and_removal_is_scoped(): void
    {
        $owner = $this->user();
        $this->authenticate($owner);
        $token = 'qa-fcm:shared-installation';
        $device = $this->register($token);
        $uri = '/api/me/push-devices/'.$device->installation_id;
        $payload = ['plateforme' => 'web', 'token' => $token];
        $nextOwner = $this->user();
        $this->authenticate($nextOwner);

        $this->deleteJson($uri)->assertNoContent();
        $this->putJson($uri, [...$payload, 'token' => 'qa-fcm:unrelated'])->assertConflict()->assertJsonPath('code', 'PUSH_DEVICE_CONFLICT');
        $this->putJson('/api/me/push-devices/'.Str::uuid(), $payload)->assertConflict()->assertDontSee($token);
        $this->assertDatabaseHas('push_devices', ['installation_id' => $device->installation_id, 'user_id' => $owner->id]);

        $this->putJson($uri, $payload)->assertOk();
        $this->assertDatabaseHas('push_devices', ['installation_id' => $device->installation_id, 'user_id' => $nextOwner->id]);
        $this->authenticate($owner);
        $this->deleteJson($uri)->assertNoContent();
        $this->assertDatabaseHas('push_devices', ['installation_id' => $device->installation_id, 'user_id' => $nextOwner->id]);
        $this->authenticate($nextOwner);
        $this->deleteJson($uri)->assertNoContent();
        $this->deleteJson($uri)->assertNoContent();
        $this->assertDatabaseMissing('push_devices', ['installation_id' => $device->installation_id]);
    }

    public function test_only_committed_notifications_queue_one_delivery_per_recipient_installation_when_enabled(): void
    {
        Queue::fake();
        $recipient = $this->user();
        $this->authenticate($recipient);
        $first = $this->register('qa-fcm:recipient-one');
        $second = $this->register('qa-fcm:recipient-two');
        $this->authenticate($this->user());
        $this->register('qa-fcm:someone-else');
        $recipient->notify($this->exampleNotification());
        Queue::assertNothingPushed();

        config(['services.fcm.enabled' => true]);
        DB::beginTransaction();
        try {
            $recipient->notify($this->exampleNotification());
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        $this->assertSame(1, $recipient->notifications()->count());
        Queue::assertNothingPushed();

        $notification = $this->exampleNotification();
        $notification->id = (string) Str::uuid();
        DB::transaction(function () use ($recipient, $notification): void {
            $recipient->notify($notification);
            Queue::assertNothingPushed();
        });
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'notifiable_id' => $recipient->id, 'read_at' => null]);
        Queue::assertPushed(SendPushNotification::class, 2);
        foreach ([$first, $second] as $device) {
            Queue::assertPushed(SendPushNotification::class, fn ($job): bool => $job->userId === $recipient->id
                && $job->notificationId === $notification->id && $job->deviceId === $device->id);
        }
        Http::assertNothingSent();
    }

    public function test_fcm_receives_a_signed_oauth_assertion_and_only_a_generic_personal_push(): void
    {
        $job = $this->delivery();
        $verificationKey = $this->fakeFirebase();

        $job->handle(app(FirebaseMessaging::class));

        Http::assertSentCount(2);
        $oauth = Http::recorded(fn (Request $request): bool => $request->url() === self::OAUTH)->sole()[0];
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $oauth['grant_type']);
        [$header, $claims, $signature] = explode('.', $oauth['assertion']);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::OAUTH, $decoded['aud']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $decoded['scope']);
        $this->assertSame(1, openssl_verify($header.'.'.$claims, base64_decode(strtr($signature, '-_', '+/')), $verificationKey, OPENSSL_ALGO_SHA256));

        $delivery = Http::recorded(fn (Request $request): bool => $request->url() === self::FCM)->sole()[0];
        $this->assertTrue($delivery->hasHeader('Authorization', 'Bearer qa-oauth-access'));
        $message = $delivery['message'];
        $this->assertSame('qa-fcm:recipient', $message['token']);
        $this->assertSame(['notification_id' => $job->notificationId], $message['data']);
        $this->assertSame(['title' => 'ANINF', 'body' => 'Vous avez une nouvelle notification.'], $message['notification']);
        $this->assertStringNotContainsString('CONFIDENTIEL', $delivery->body());
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'read_at' => null]);
    }

    public function test_an_old_job_cannot_deliver_after_an_account_switch_or_with_another_users_notification(): void
    {
        $job = $this->delivery();
        $device = PushDevice::query()->findOrFail($job->deviceId);
        $nextOwner = $this->user();
        $this->authenticate($nextOwner);
        $this->putJson('/api/me/push-devices/'.$device->installation_id, ['token' => $device->token, 'plateforme' => 'web'])->assertOk();
        $currentId = PushDevice::query()->where('installation_id', $device->installation_id)->value('id');
        config(['services.fcm.enabled' => true]);

        $job->handle(app(FirebaseMessaging::class));
        (new SendPushNotification($nextOwner->id, $job->notificationId, $currentId))->handle(app(FirebaseMessaging::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('push_devices', ['id' => $currentId, 'user_id' => $nextOwner->id]);
    }

    public function test_unregistered_fcm_token_removes_only_its_installation_without_deleting_the_notification(): void
    {
        $job = $this->delivery();
        $other = $this->register('qa-fcm:still-valid');
        $this->fakeFirebase(404, ['error' => ['details' => [[
            '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
        ]]]]);

        $job->handle(app(FirebaseMessaging::class));

        $this->assertDatabaseMissing('push_devices', ['id' => $job->deviceId]);
        $this->assertModelExists($other);
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId]);
    }

    #[DataProvider('providerErrors')]
    public function test_provider_failures_preserve_the_installation_and_inbox(int $status, ?int $retryIn): void
    {
        $job = $this->delivery();
        $this->fakeFirebase($status, ['error' => ['message' => 'CONFIDENTIEL']], $status === 429 ? ['Retry-After' => '145'] : []);
        $job->withFakeQueueInteractions()->handle(app(FirebaseMessaging::class));

        if ($retryIn === null) {
            $job->assertFailed();
            $this->assertStringNotContainsString('CONFIDENTIEL', $job->job->failedWith->getMessage());
        } else {
            $job->assertReleased($retryIn);
        }
        $this->assertDatabaseHas('push_devices', ['id' => $job->deviceId]);
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'read_at' => null]);
    }

    public static function providerErrors(): array
    {
        return [
            'quota' => [429, 145],
            'incident temporaire' => [503, 60],
            'projet introuvable' => [404, null],
        ];
    }

    private function user(): User
    {
        return User::factory()->create(['statut' => 'ACTIF', 'must_change_password' => false]);
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test-push')->plainTextToken);
    }

    private function register(string $token): PushDevice
    {
        $installation = (string) Str::uuid();
        $this->putJson('/api/me/push-devices/'.$installation, ['plateforme' => 'android', 'token' => $token])->assertOk()->assertDontSee($token);

        return PushDevice::query()->where('installation_id', $installation)->sole();
    }

    private function exampleNotification(): TreatmentNotification
    {
        return new TreatmentNotification('DEMANDE_VALIDEE', 'Message CONFIDENTIEL', 83, 24, 'CONFIDENTIEL');
    }

    private function delivery(): SendPushNotification
    {
        $user = $this->user();
        $this->authenticate($user);
        $device = $this->register('qa-fcm:recipient');
        $user->notify($this->exampleNotification());

        return new SendPushNotification($user->id, $user->notifications()->sole()->id, $device->id);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function fakeFirebase(int $status = 200, array $body = ['name' => 'projects/aninf-test/messages/1'], array $headers = []): string
    {
        $disk = Storage::fake('local');
        $disk->put('openssl.cnf', "[req]\ndistinguished_name=req_distinguished_name\n[req_distinguished_name]\n");
        $options = ['config' => $disk->path('openssl.cnf'), 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $pem, null, $options));
        $disk->put('service-account.json', json_encode([
            'type' => 'service_account', 'project_id' => 'aninf-test', 'client_email' => 'push@example.test', 'private_key' => $pem,
        ], JSON_THROW_ON_ERROR));
        config(['services.fcm.enabled' => true, 'services.fcm.credentials' => $disk->path('service-account.json')]);
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'qa-oauth-access', 'expires_in' => 3600]),
            self::FCM => Http::response($body, $status, $headers),
        ]);

        return openssl_pkey_get_details($key)['key'];
    }
}
