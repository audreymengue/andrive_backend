<?php

namespace App\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TreatmentNotification extends Notification
{
    public function __construct(
        public readonly string $evenement,
        public readonly string $message,
        public readonly int $courseId,
        public readonly ?int $demandeId = null,
        public readonly ?string $referenceDemande = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function afterSending(object $notifiable, string $channel, mixed $response): void
    {
        if ($channel !== 'database' || ! config('services.fcm.enabled') || ! $notifiable instanceof User || ! $response instanceof DatabaseNotification) {
            return;
        }

        $userId = (int) $notifiable->getKey();
        $notificationId = (string) $response->getKey();
        DB::afterCommit(static function () use ($userId, $notificationId): void {
            try {
                foreach (PushDevice::query()->where('user_id', $userId)->pluck('id') as $deviceId) {
                    Bus::dispatch(new SendPushNotification($userId, $notificationId, (int) $deviceId));
                }
            } catch (Throwable) {
                Log::warning('Échec de mise en file du push pour une notification interne.', ['notification_id' => $notificationId]);
            }
        });
    }

    public function databaseType(object $notifiable): string
    {
        return 'traitement';
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'evenement' => $this->evenement,
            'message' => $this->message,
            'course_id' => $this->courseId,
            'demande_id' => $this->demandeId,
            'reference_demande' => $this->referenceDemande,
        ];
    }
}
