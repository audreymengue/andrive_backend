<?php

namespace App\Models;

use Database\Factories\DirectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Direction extends Model
{
    /** @use HasFactory<DirectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nom',
        'code',
        'description',
        'statut',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function rattachements(): HasMany
    {
        return $this->hasMany(RattachementDirection::class)->orderBy('id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class);
    }
}
