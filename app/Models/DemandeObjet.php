<?php

namespace App\Models;

use Database\Factories\DemandeObjetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeObjet extends Model
{
    /** @use HasFactory<DemandeObjetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'demande_id',
        'designation',
        'quantite',
        'description',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }
}
