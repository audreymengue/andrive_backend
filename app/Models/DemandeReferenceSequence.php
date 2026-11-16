<?php

namespace App\Models;

use Database\Factories\DemandeReferenceSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemandeReferenceSequence extends Model
{
    /** @use HasFactory<DemandeReferenceSequenceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference_date',
        'last_sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_date' => 'date:Y-m-d',
            'last_sequence' => 'integer',
        ];
    }
}
