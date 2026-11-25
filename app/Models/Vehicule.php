<?php

namespace App\Models;

use Database\Factories\VehiculeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicule extends Model
{
    /** @use HasFactory<VehiculeFactory> */
    use HasFactory;

    protected $table = 'vehicules';

    protected $fillable = ['immatriculation', 'marque', 'modele', 'places_utiles', 'statut'];

    protected $hidden = ['immatriculation_source'];

    protected function casts(): array
    {
        return ['places_utiles' => 'integer', 'lock_version' => 'integer'];
    }
}
