<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = ['created_by_id', 'statut_execution', 'lock_version', 'demarree_a', 'terminee_a', 'chauffeur_libere_a', 'vehicule_libere_a'];

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
            'demarree_a' => 'datetime',
            'terminee_a' => 'datetime',
            'chauffeur_libere_a' => 'datetime',
            'vehicule_libere_a' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function preparations(): HasMany
    {
        return $this->hasMany(CoursePreparation::class)->orderBy('version');
    }

    public function currentPreparation(): HasOne
    {
        return $this->hasOne(CoursePreparation::class)->latestOfMany('version');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CourseEvent::class);
    }
}
