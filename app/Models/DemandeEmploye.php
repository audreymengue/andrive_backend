<?php

namespace App\Models;

use Database\Factories\DemandeEmployeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeEmploye extends Model
{
    /** @use HasFactory<DemandeEmployeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'demande_id',
        'nom',
        'prenom',
        'fonction',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }
}
