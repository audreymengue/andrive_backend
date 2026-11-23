<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfficialOrganisationSeeder extends Seeder
{
    /**
     * Seed official Directions and Services additively.
     *
     * The supplied list has no code for Direction Generale or for Services, so
     * stable technical codes are derived from their names. The DCM source repeats
     * one Service verbatim; it is seeded once. The Direction Generale positions
     * are retained in its description, not converted into unapproved Services.
     */
    public function run(): void
    {
        $directions = [
            [
                'nom' => 'Direction Générale',
                'code' => 'DG',
                'description' => 'Conseillers, Chargés d’Etude, Coordonnateur, Cabinet et autre',
                'services' => [],
            ],
            [
                'nom' => 'Direction de l’Ingénierie et des Réseaux',
                'code' => 'DIR',
                'description' => null,
                'services' => [
                    'Service Exploitation',
                    'Service Télécom',
                    'Service Réseaux Locaux',
                    'Service Énergie et Environnement',
                ],
            ],
            [
                'nom' => 'Direction des Applications',
                'code' => 'DAP',
                'description' => null,
                'services' => [
                    'Service Conception et développement',
                    'Service Infrastructure et Hébergement',
                    'Service Prestations aux usagers',
                ],
            ],
            [
                'nom' => 'Direction de la Sécurité et de l’Information',
                'code' => 'DISI',
                'description' => null,
                'services' => [
                    'Service Sécurité des Réseaux et de l’information',
                    'Service Centre Opérationnel de la Sécurité des SI',
                ],
            ],
            [
                'nom' => 'Direction des Fréquences et de l’Audiovisuel',
                'code' => 'DFA',
                'description' => null,
                'services' => [
                    'Service Gestion Administrative des Fréquences',
                    'Service des inspections et du contrôle des fréquences radioélectriques',
                    'Service Planification et Coordination des Fréquences',
                ],
            ],
            [
                'nom' => 'Direction des Ressources Humaines',
                'code' => 'DRH',
                'description' => null,
                'services' => [
                    'Service Recrutement et Gestion des Carrières',
                    'Service Rémunération et Capital Social',
                    'Service Formation et Développement des Compétences',
                ],
            ],
            [
                'nom' => 'Direction Financière',
                'code' => 'DF',
                'description' => null,
                'services' => [
                    'Service Achats et voyages',
                    'Service Contrôle de Gestion',
                    'Service financier',
                    'Service Comptabilité et facturation',
                    'Service Recouvrement',
                ],
            ],
            [
                'nom' => 'Direction des Affaires Générales',
                'code' => 'DAG',
                'description' => null,
                'services' => [
                    'Service Approvisionnement et Stock',
                    'Service Moyens Généraux',
                    'Service Archives et Documentation',
                ],
            ],
            [
                'nom' => 'Direction Juridique',
                'code' => 'DJ',
                'description' => null,
                'services' => [
                    'Service Coopérations Internationales',
                ],
            ],
            [
                'nom' => 'Direction de l’Audit, de la Qualité, de la Stratégie et de l’Organisation',
                'code' => 'DAQSO',
                'description' => null,
                'services' => [
                    'Service Audit',
                    'Service Qualité, Hygiène, Sécurité et Environnement',
                    'Service Stratégie et Organisation',
                ],
            ],
            [
                'nom' => 'Direction de la Communication et du Marketing',
                'code' => 'DCM',
                'description' => null,
                'services' => [
                    'Service Relations Publiques et Media',
                ],
            ],
        ];

        DB::transaction(function () use ($directions): void {
            foreach ($directions as $directionData) {
                $direction = Direction::query()->firstOrCreate(
                    ['code' => $directionData['code']],
                    [
                        'nom' => $directionData['nom'],
                        'description' => $directionData['description'],
                        'statut' => 'ACTIF',
                    ],
                );

                foreach ($directionData['services'] as $serviceName) {
                    Service::query()->firstOrCreate(
                        [
                            'direction_id' => $direction->id,
                            'code' => Str::upper(Str::slug($serviceName, '_')),
                        ],
                        [
                            'nom' => $serviceName,
                            'description' => null,
                            'statut' => 'ACTIF',
                        ],
                    );
                }
            }
        });
    }
}
