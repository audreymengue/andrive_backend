<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CoursePreparation extends Model
{
    protected $fillable = ['course_id', 'version', 'actor_id', 'chauffeur_id', 'vehicule_id', 'plan'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'chauffeur_id' => 'integer', 'vehicule_id' => 'integer', 'plan' => 'array'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class);
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function demandes(): BelongsToMany
    {
        return $this->belongsToMany(Demande::class, 'course_preparation_demandes')->withTimestamps()->orderBy('demandes.id');
    }
}
