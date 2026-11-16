<?php

namespace App\Models;

use Database\Factories\SignalementTransportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalementTransport extends Model
{
    /** @use HasFactory<SignalementTransportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['reported_by_id', 'chauffeur_id', 'vehicule_id', 'course_id', 'type', 'details', 'signale_a'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Un signalement Transport est immuable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Un signalement Transport est immuable.');
        });
    }

    protected function casts(): array
    {
        return [
            'reported_by_id' => 'integer',
            'chauffeur_id' => 'integer',
            'vehicule_id' => 'integer',
            'course_id' => 'integer',
            'signale_a' => 'immutable_datetime',
        ];
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class);
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
