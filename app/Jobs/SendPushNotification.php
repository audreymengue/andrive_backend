<?php

namespace App\Jobs;

use App\Models\PushDevice;
use App\Models\User;
use App\Services\FirebaseMessaging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 35;

    /** @var list<int> */
    public array $backoff = [60, 120, 240];

    public function __construct(public readonly int $userId, public readonly string $notificationId, public readonly int $deviceId)
    {
        $this->onConnection('database');
    }

    public function handle(FirebaseMessaging $messaging): void
    {
        if (! config('services.fcm.enabled') || ! $this->eligibleDevice()->exists()) {
            return;
        }

        $messaging->authorize();
        $response = DB::transaction(function () use ($messaging): ?Response {
            // Serialize a token transfer or logout with this single, time-limited FCM send.
            $device = $this->eligibleDevice()->lockForUpdate()->first();
            if ($device === null) {
                return null;
            }

            $response = $messaging->send($device->token, $this->notificationId);
            if ($response->notFound() && collect($response->json('error.details'))->contains(
                fn (mixed $error): bool => is_array($error)
                    && ($error['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                    && ($error['errorCode'] ?? null) === 'UNREGISTERED'
            )) {
                PushDevice::query()->whereKey($device->id)->where('user_id', $this->userId)
                    ->where('personal_access_token_id', $device->personal_access_token_id)
                    ->where('token_hash', $device->token_hash)->delete();

                return null;
            }

            return $response;
        });

        if ($response === null || $response->successful()) {
            return;
        }

        if (($response->tooManyRequests() || $response->serverError() || $response->unauthorized()) && $this->attempts() < $this->tries) {
            $retryAfter = $response->header('Retry-After');
            $delay = $this->backoff[$this->attempts() - 1];
            if (is_string($retryAfter) && $retryAfter !== '') {
                $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : (int) strtotime($retryAfter) - now()->timestamp;
                $delay = max($delay, $seconds);
            }
            $this->release($delay);

            return;
        }

        $this->fail('Envoi de notification push refusé (HTTP '.$response->status().').');
    }

    /** @return Builder<PushDevice> */
    private function eligibleDevice(): Builder
    {
        $recipient = User::query()->select('id')->whereKey($this->userId)
            ->where('statut', 'ACTIF')->where('must_change_password', false)
            ->whereHas('notifications', fn (Builder $query) => $query->whereKey($this->notificationId)->where('type', 'traitement'));

        return PushDevice::query()->whereKey($this->deviceId)->where('user_id', $this->userId)
            ->whereExists($recipient->toBase())
            ->whereHas('personalAccessToken', function (Builder $query): void {
                $query->where('tokenable_type', (new User)->getMorphClass())->where('tokenable_id', $this->userId)
                    ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                if ($minutes = config('sanctum.expiration')) {
                    $query->where('created_at', '>', now()->subMinutes((int) $minutes));
                }
            });
    }
}
