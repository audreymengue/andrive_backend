<?php

namespace App\Models;

use Database\Factories\ChauffeurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chauffeur extends Model
{
    /** @use HasFactory<ChauffeurFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'nom_complet', 'mention_pool', 'telephone', 'statut'];

    protected $hidden = ['telephone', 'reference_source'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'lock_version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
