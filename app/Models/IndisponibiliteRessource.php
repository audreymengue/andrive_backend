<?php

namespace App\Models;

use Database\Factories\IndisponibiliteRessourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndisponibiliteRessource extends Model
{
    /** @use HasFactory<IndisponibiliteRessourceFactory> */
    use HasFactory;

    protected $fillable = ['vehicule_id', 'chauffeur_id', 'created_by_id', 'debut_a', 'fin_estimee_a', 'fin_effective_a', 'motif', 'lock_version'];

    protected function casts(): array
    {
        return [
            'vehicule_id' => 'integer',
            'chauffeur_id' => 'integer',
            'created_by_id' => 'integer',
            'debut_a' => 'immutable_datetime',
            'fin_estimee_a' => 'immutable_datetime',
            'fin_effective_a' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransportResourceEvent::class);
    }
}
