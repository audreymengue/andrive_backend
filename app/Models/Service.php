<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $fillable = ['direction_id', 'nom', 'code', 'description', 'statut'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }
}
