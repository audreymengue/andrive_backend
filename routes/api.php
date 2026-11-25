<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DemandeController;
use App\Http\Controllers\EntiteGeographiqueController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\ParametrageJournalController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PushDeviceController;
use App\Http\Controllers\ResourceProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TemporaryPasswordController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\TreatmentDemandeController;
use App\Http\Controllers\TypeSortieController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\SerializeAuthentication;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware(['throttle:login', SerializeAuthentication::class])->defaults('authentication_api', true);

Route::middleware(['auth:sanctum', SerializeAuthentication::class])->group(function (): void {
    foreach (['direction' => 'directions', 'service' => 'services'] as $kind => $path) {
        $controller = OrganisationController::class;
        $configure = static function (Illuminate\Routing\Route $route, string $permission, string $action, bool $admin = true) use ($kind): void {
            $route->defaults('organisation_kind', $kind)->defaults('organisation_permission', $permission)->defaults('organisation_action', $action)->defaults('organisation_admin', $admin);
        };
        $configure(Route::get('/parametrage/'.$path, [$controller, 'index']), 'read_'.$kind, 'list');
        if ($kind === 'direction') {
            $configure(Route::get('/'.$path, [$controller, 'index']), 'read_direction', 'list', false);
        }
        if ($kind === 'service') {
            $configure(Route::get('/'.$path, [$controller, 'index']), 'read_service', 'list', false);
        }
        $configure(Route::post('/'.$path, [$controller, 'mutate']), 'create_'.$kind, 'create');
        $configure(Route::get('/'.$path.'/{id}', [$controller, 'show'])->whereNumber('id'), 'read_'.$kind, 'show');
        $configure(Route::put('/'.$path.'/{id}', [$controller, 'mutate'])->whereNumber('id'), 'update_'.$kind, 'update');
        foreach (['activate', 'deactivate'] as $action) {
            $configure(Route::put('/'.$path.'/{id}/'.$action, [$controller, 'mutate'])->whereNumber('id'), $action.'_'.$kind, $action);
        }
    }
    Route::post('/users/{id}/temporary-password/reveal', TemporaryPasswordController::class)->whereNumber('id')->middleware('parametrage:read_temporary_password,user')->defaults('parametrage_api', true);
    Route::get('/users/{id}/organisation-history', [ParametrageJournalController::class, 'history'])->whereNumber('id')->defaults('journal_permission', 'read_user')->defaults('journal_action', 'history');
    Route::get('/parametrage/audit', [ParametrageJournalController::class, 'index'])->defaults('journal_permission', 'read_parametrage_audit')->defaults('journal_action', 'index');
    Route::get('/parametrage/audit/{id}', [ParametrageJournalController::class, 'show'])->where('id', '(administration|maintenance):[1-9][0-9]*')->defaults('journal_permission', 'read_parametrage_audit')->defaults('journal_action', 'show');
    Route::get('/me', [AuthController::class, 'me'])->defaults('authentication_api', true);
    Route::post('/logout', [AuthController::class, 'logout'])->defaults('authentication_api', true);
    Route::put('/me/password', [AuthController::class, 'updatePassword'])->middleware(SerializeAuthentication::class)->defaults('authentication_api', true);

    Route::middleware('password.changed')->group(function (): void {
        Route::put('/me/push-devices/{installation_id}', [PushDeviceController::class, 'update'])->defaults('notification_api', true);
        Route::delete('/me/push-devices/{installation_id}', [PushDeviceController::class, 'destroy'])->defaults('notification_api', true);

        Route::get('/notifications', [NotificationController::class, 'index'])->defaults('notification_api', true);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->defaults('notification_api', true);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->defaults('notification_api', true);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead'])->defaults('notification_api', true);

        foreach (['vehicule' => ['path' => 'vehicules', 'permission' => 'vehicle'], 'chauffeur' => ['path' => 'chauffeurs', 'permission' => 'driver']] as $kind => $configuration) {
            $configure = static function (Illuminate\Routing\Route $route, string $action) use ($kind, $configuration): void {
                $route->defaults('resource_profile_kind', $kind)
                    ->defaults('resource_profile_action', $action)
                    ->defaults('resource_profile_permission', $action === 'list' || $action === 'show' ? 'read_'.$configuration['permission'] : ($action === 'create' ? 'create_'.$configuration['permission'] : ($action === 'update' ? 'update_'.$configuration['permission'] : $action.'_'.$configuration['permission'])));
            };

            $configure(Route::middleware('permission:read_'.$configuration['permission'])->get('/parametrage/'.$configuration['path'], [ResourceProfileController::class, 'index']), 'list');
            $configure(Route::middleware('permission:create_'.$configuration['permission'])->post('/parametrage/'.$configuration['path'], [ResourceProfileController::class, 'store']), 'create');
            $configure(Route::middleware('permission:read_'.$configuration['permission'])->get('/parametrage/'.$configuration['path'].'/{id}', [ResourceProfileController::class, 'show'])->whereNumber('id'), 'show');
            $configure(Route::middleware('permission:update_'.$configuration['permission'])->put('/parametrage/'.$configuration['path'].'/{id}', [ResourceProfileController::class, 'update'])->whereNumber('id'), 'update');
            foreach (['activate', 'deactivate'] as $action) {
                $configure(Route::middleware('permission:'.$action.'_'.$configuration['permission'])->put('/parametrage/'.$configuration['path'].'/{id}/'.$action, [ResourceProfileController::class, $action])->whereNumber('id'), $action);
            }
        }

        Route::middleware('parametrage:read_type_sortie,type_sortie')->get('/parametrage/types-sortie', [TypeSortieController::class, 'adminIndex'])->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('permission:read_type_sortie')->get('/types-sortie', [TypeSortieController::class, 'index'])->defaults('reference_catalog_api', true);
        Route::middleware('parametrage:activate_type_sortie,type_sortie')->put('/types-sortie/{id}/activate', [TypeSortieController::class, 'activate'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:deactivate_type_sortie,type_sortie')->put('/types-sortie/{id}/deactivate', [TypeSortieController::class, 'deactivate'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:create_type_sortie,type_sortie')->post('/types-sortie', [TypeSortieController::class, 'store'])->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:read_type_sortie,type_sortie')->get('/types-sortie/{id}', [TypeSortieController::class, 'show'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:update_type_sortie,type_sortie')->put('/types-sortie/{id}', [TypeSortieController::class, 'update'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:delete_type_sortie,type_sortie')->delete('/types-sortie/{id}', [TypeSortieController::class, 'destroy'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:read_entite_geographique,entite_geographique')->get('/parametrage/entites-geographiques', [EntiteGeographiqueController::class, 'adminIndex'])->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('permission:read_entite_geographique')->get('/entites-geographiques', [EntiteGeographiqueController::class, 'index'])->defaults('reference_catalog_api', true);
        Route::middleware('parametrage:activate_entite_geographique,entite_geographique')->put('/entites-geographiques/{id}/activate', [EntiteGeographiqueController::class, 'activate'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:deactivate_entite_geographique,entite_geographique')->put('/entites-geographiques/{id}/deactivate', [EntiteGeographiqueController::class, 'deactivate'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:create_entite_geographique,entite_geographique')->post('/entites-geographiques', [EntiteGeographiqueController::class, 'store'])->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:read_entite_geographique,entite_geographique')->get('/entites-geographiques/{id}', [EntiteGeographiqueController::class, 'show'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:update_entite_geographique,entite_geographique')->put('/entites-geographiques/{id}', [EntiteGeographiqueController::class, 'update'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:delete_entite_geographique,entite_geographique')->delete('/entites-geographiques/{id}', [EntiteGeographiqueController::class, 'destroy'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('permission:read_demande_traitement')->get('/traitement/demandes', [TreatmentDemandeController::class, 'index'])->defaults('treatment_api', true);
        Route::middleware('permission:read_demande_traitement')->get('/traitement/demandes/{id}', [TreatmentDemandeController::class, 'show'])->whereNumber('id')->defaults('treatment_api', true);
        Route::middleware('permission:read_course')->get('/courses', [CourseController::class, 'index'])->defaults('treatment_api', true);
        Route::middleware('permission:create_course')->post('/courses', [CourseController::class, 'store'])->defaults('treatment_api', true);
        Route::middleware('permission:read_course')->get('/courses/{id}', [CourseController::class, 'show'])->whereNumber('id')->defaults('treatment_api', true);
        Route::middleware('permission:update_course')->put('/courses/{id}', [CourseController::class, 'update'])->whereNumber('id')->defaults('treatment_api', true);
        Route::middleware('permission:confirm_course')->post('/courses/{id}', [CourseController::class, 'confirm'])->whereNumber('id')->defaults('treatment_api', true);
        Route::middleware('permission:start_course')->post('/courses/{id}/depart', [CourseController::class, 'start'])->whereNumber('id')->defaults('treatment_api', true);
        Route::middleware('permission:finish_course')->post('/courses/{id}/terminer', [CourseController::class, 'finish'])->whereNumber('id')->defaults('treatment_api', true);

        Route::middleware('permission:read_transport_signalement')->get('/transport/signalements', [TransportController::class, 'signalements'])->defaults('transport_api', true);
        Route::middleware('permission:report_course_incident')->post('/transport/signalements', [TransportController::class, 'storeSignalement'])->defaults('transport_api', true);
        Route::middleware('permission:read_transport_signalement')->get('/transport/signalements/{id}', [TransportController::class, 'showSignalement'])->whereNumber('id')->defaults('transport_api', true);
        Route::middleware('permission:read_resource_availability')->get('/transport/indisponibilites', [TransportController::class, 'indisponibilites'])->defaults('transport_api', true);
        Route::middleware('permission:manage_resource_availability')->post('/transport/indisponibilites', [TransportController::class, 'storeIndisponibilite'])->defaults('transport_api', true);
        Route::middleware('permission:read_resource_availability')->get('/transport/indisponibilites/{id}', [TransportController::class, 'showIndisponibilite'])->whereNumber('id')->defaults('transport_api', true);
        Route::middleware('permission:manage_resource_availability')->put('/transport/indisponibilites/{id}', [TransportController::class, 'updateIndisponibilite'])->whereNumber('id')->defaults('transport_api', true);
        Route::middleware('permission:confirm_resource_return')->post('/transport/indisponibilites/{id}/remise-en-service', [TransportController::class, 'confirmReturn'])->whereNumber('id')->defaults('transport_api', true);
        Route::middleware('permission:read_demande')->get('/demandes', [DemandeController::class, 'index']);
        Route::middleware('permission:create_demande')->post('/demandes', [DemandeController::class, 'store']);
        Route::middleware('permission:read_demande')->get('/demandes/statut', [DemandeController::class, 'status']);
        Route::middleware('permission:read_demande')->get('/demandes/{id}', [DemandeController::class, 'show'])->whereNumber('id');
        Route::middleware('permission:update_demande')->put('/demandes/{id}', [DemandeController::class, 'update'])->whereNumber('id');
        Route::middleware('permission:submit_demande')->post('/demandes/{id}/soumission', [DemandeController::class, 'submit'])->whereNumber('id');
        Route::middleware('permission:cancel_demande')->post('/demandes/{id}/annulation', [DemandeController::class, 'cancel'])->whereNumber('id');
        Route::middleware('permission:update_demande')->post('/demandes/{id}/modification', [DemandeController::class, 'requestModification'])->whereNumber('id');
        Route::middleware('permission:update_demande')->put('/demandes/{id}/modification', [DemandeController::class, 'updateModification'])->whereNumber('id');
        Route::middleware('permission:submit_demande')->post('/demandes/{id}/modification/soumission', [DemandeController::class, 'submitModification'])->whereNumber('id');

        Route::middleware('parametrage:read_user,user')->get('/users', [UserController::class, 'index'])->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:create_user,user')->post('/users', [UserController::class, 'store'])->defaults('parametrage_api', true);
        Route::middleware('parametrage:read_user,user')->get('/users/{id}', [UserController::class, 'show'])->whereNumber('id')->defaults('reference_catalog_api', true)->defaults('parametrage_api', true);
        Route::middleware('parametrage:update_user,user')->put('/users/{id}', [UserController::class, 'update'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:activate_user,user')->patch('/users/{id}/activate', [UserController::class, 'activate'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:deactivate_user,user')->patch('/users/{id}/deactivate', [UserController::class, 'deactivate'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:reset_user_password,user')->post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:assign_role,user')->put('/users/{id}/roles', [UserController::class, 'syncRoles'])->whereNumber('id')->defaults('parametrage_api', true);

        Route::middleware('parametrage:read_role,role')->get('/roles', [RoleController::class, 'index'])->defaults('parametrage_api', true);
        Route::middleware('parametrage:create_role,role')->post('/roles', [RoleController::class, 'store'])->defaults('parametrage_api', true);
        Route::middleware('parametrage:read_role,role')->get('/roles/{id}', [RoleController::class, 'show'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:update_role,role')->put('/roles/{id}', [RoleController::class, 'update'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:delete_role,role')->delete('/roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:assign_permission,role')->put('/roles/{id}/permissions', [RoleController::class, 'syncPermissions'])->whereNumber('id')->defaults('parametrage_api', true);

        Route::middleware('parametrage:read_permission,permission')->get('/permissions', [PermissionController::class, 'index'])->defaults('parametrage_api', true);
        Route::middleware('parametrage:create_permission,permission')->post('/permissions', [PermissionController::class, 'store'])->defaults('parametrage_api', true);
        Route::middleware('parametrage:read_permission,permission')->get('/permissions/{id}', [PermissionController::class, 'show'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:update_permission,permission')->put('/permissions/{id}', [PermissionController::class, 'update'])->whereNumber('id')->defaults('parametrage_api', true);
        Route::middleware('parametrage:delete_permission,permission')->delete('/permissions/{id}', [PermissionController::class, 'destroy'])->whereNumber('id')->defaults('parametrage_api', true);
    });
});
