<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var array<string, string> $permissions */
        $permissions = [
            'read_user' => 'Lire les utilisateurs',
            'create_user' => 'Créer un utilisateur',
            'update_user' => 'Modifier un utilisateur',
            'activate_user' => 'Activer un utilisateur',
            'deactivate_user' => 'Désactiver un utilisateur',
            'reset_user_password' => 'Réinitialiser le mot de passe d’un utilisateur',
            'assign_role' => 'Assigner un rôle',
            'read_role' => 'Lire les rôles',
            'create_role' => 'Créer un rôle',
            'update_role' => 'Modifier un rôle',
            'delete_role' => 'Supprimer un rôle',
            'assign_permission' => 'Assigner une permission',
            'read_permission' => 'Lire les permissions',
            'create_permission' => 'Créer une permission',
            'update_permission' => 'Modifier une permission',
            'delete_permission' => 'Supprimer une permission',
            'read_demande_traitement' => 'Lire les demandes à traiter',
            'read_course' => 'Lire les Courses',
            'create_course' => 'Créer une préparation de Course',
            'update_course' => 'Modifier une préparation de Course',
            'confirm_course' => 'Confirmer une Course',
            'start_course' => 'Démarrer une Course affectée',
            'finish_course' => 'Terminer une Course et attester les libérations',
            'read_demande' => 'Lire les demandes',
            'create_demande' => 'Créer une demande',
            'update_demande' => 'Modifier une demande',
            'submit_demande' => 'Soumettre une demande',
            'cancel_demande' => 'Annuler une demande',
            'read_type_sortie' => 'Lire les types de sortie',
            'create_type_sortie' => 'Créer un type de sortie',
            'update_type_sortie' => 'Modifier un type de sortie',
            'delete_type_sortie' => 'Supprimer un type de sortie',
            'read_entite_geographique' => 'Lire les entités géographiques',
            'create_entite_geographique' => 'Créer une entité géographique',
            'update_entite_geographique' => 'Modifier une entité géographique',
            'delete_entite_geographique' => 'Supprimer une entité géographique',
            'read_vehicle' => 'Lire les véhicules',
            'create_vehicle' => 'Créer un profil véhicule',
            'update_vehicle' => 'Modifier un profil véhicule',
            'activate_vehicle' => 'Activer un profil véhicule',
            'deactivate_vehicle' => 'Désactiver un profil véhicule',
            'read_driver' => 'Lire les profils chauffeur',
            'create_driver' => 'Créer un profil chauffeur',
            'update_driver' => 'Modifier un profil chauffeur',
            'activate_driver' => 'Activer un profil chauffeur',
            'deactivate_driver' => 'Désactiver un profil chauffeur',
            'report_course_incident' => 'Signaler un incident de Course',
            'read_transport_signalement' => 'Lire les signalements Transport',
            'read_resource_availability' => 'Lire les indisponibilités des ressources',
            'manage_resource_availability' => 'Gérer les indisponibilités des ressources',
            'confirm_resource_return' => 'Confirmer une remise en service',
        ];

        foreach ($permissions as $code => $libelle) {
            Permission::query()->updateOrCreate(
                ['name' => $code, 'guard_name' => 'web'],
                ['libelle' => $libelle],
            );
        }
    }
}
