<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

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
