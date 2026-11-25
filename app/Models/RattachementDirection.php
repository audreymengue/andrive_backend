<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RattachementDirection extends Model
{
    protected $table = 'rattachements_direction';

    protected $fillable = ['direction_id', 'code', 'nom'];

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }
}
