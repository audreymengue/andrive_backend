<?php

namespace App\Models;

use Database\Factories\DemandeHistoriqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeHistorique extends Model
{
    /** @use HasFactory<DemandeHistoriqueFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'demande_id',
        'ancien_statut',
        'nouveau_statut',
        'utilisateur_id',
        'commentaire',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
