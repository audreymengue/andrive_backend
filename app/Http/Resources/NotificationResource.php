<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'evenement' => $this->data['evenement'],
            'message' => $this->data['message'],
            'course_id' => (int) $this->data['course_id'],
            'demande_id' => isset($this->data['demande_id']) ? (int) $this->data['demande_id'] : null,
            'reference_demande' => $this->data['reference_demande'] ?? null,
            'created_at' => $this->created_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
        ];
    }
}
