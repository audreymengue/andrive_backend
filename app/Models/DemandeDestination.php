<?php

namespace App\Models;

use App\Services\DemandeReferences;
use Database\Factories\DemandeDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeDestination extends Model
{
    /** @use HasFactory<DemandeDestinationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (self $record): void {
            $fields = ['entite_geographique_id'];
            if ($record->isDirty($fields)) {
                DemandeReferences::recordUsage(['destinations' => [$record->only(['entite_geographique_id'])]]);
            }
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'demande_id',
        'entite_geographique_id',
        'ordre',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }

    public function entiteGeographique(): BelongsTo
    {
        return $this->belongsTo(EntiteGeographique::class);
    }
}
