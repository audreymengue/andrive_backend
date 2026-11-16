<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportResourceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['indisponibilite_ressource_id', 'actor_id', 'action', 'version', 'before_state', 'after_state', 'motif_evenement', 'command_key', 'created_at'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Un événement Transport est immuable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Un événement Transport est immuable.');
        });
    }

    protected function casts(): array
    {
        return [
            'indisponibilite_ressource_id' => 'integer',
            'actor_id' => 'integer',
            'version' => 'integer',
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(IndisponibiliteRessource::class, 'indisponibilite_ressource_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
