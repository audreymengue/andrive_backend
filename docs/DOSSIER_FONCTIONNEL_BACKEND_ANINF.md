# Dossier fonctionnel du backend ANINF
## Fonctionnalités, règles métier et choix de conception

**État examiné :** dépôt `aninf-api-test`, commit `391ffa3`  
**Date :** 20 novembre 2026  
**Destinataires :** réunion projet, équipe backend, intégration Angular et Flutter

---

## 1. Objet et fiabilité

Ce dossier explique les fonctionnalités et règles métier matérialisées dans le backend Laravel. Il distingue les faits observés dans le code, les règles indiquées par OpenAPI et les décisions qui restent à arbitrer.

**Niveaux de preuve employés**
- **Code vérifié** : route, contrôleur, middleware, service, modèle, migration ou seeder inspecté.
- **Contrat** : règle ou disponibilité déclarée dans `openapi.yaml`; l’annotation seule ne prouve pas l’exécution.
- **À confirmer** : arbitrage institutionnel ou métier non démontré par le code.

Aucun test, appel HTTP, migration, seeder ou accès à une base n’a été exécuté pour ce document. Les tests cités plus bas sont des fichiers présents, pas des résultats nouvellement obtenus.

### Réserve documentaire importante

`docs/SPECIFICATION_MODULE_PARAMETRAGE.md` date du 27 octobre 2026 et annonce une conception **non implémentée**. Elle décrit donc un état antérieur, tandis que plusieurs protections qu’elle préconisait existent aujourd’hui dans les services et routes. Elle sert de référence d’intention, pas d’inventaire actuel.

`openapi.yaml` présente une incohérence de statut : dans les 99 opérations sous `paths`, elles sont annotées `implemented`; la section `x-current-contract` donne 74 opérations `implemented` et 25 `partial`. Ces 25 opérations sont pourtant représentées dans `routes/api.php` et leurs contrôleurs. Cette présence ne prouve pas une conformité complète de tous les détails de schéma. **L’état du contrat doit être harmonisé et comparé opération par opération avant d’être annoncé comme référence frontend sans réserve.**

---

## 2. Carte fonctionnelle

Le dépôt contient les domaines suivants : Authentification; Utilisateurs/Rôles/Permissions; Directions/Services; Types de sortie et Entités géographiques; Demandes; Traitement/Courses; profils Véhicule et Chauffeur; indisponibilités et signalements Transport; Notifications; historique organisationnel et audit.

Les routes sont déclarées sous `/api` dans `routes/api.php`. Les contrôles sont assurés par Sanctum, le middleware `password.changed`, les middlewares de permissions Spatie et la couche `ParametrageSecurity`. Le frontend peut adapter les menus aux rôles, mais le backend reste l’autorité d’accès à chaque opération.

## 3. Acteurs et permissions

### Rôles

| Rôle | État démontré |
|---|---|
| `super_admin` | Rôle système identifié par `system_key`. L’administration Paramétrage exige ce rôle actif et la permission de l’action. |
| `demandeur` | Rôle système auquel la couche Paramétrage attribue les droits usuels de Demande et de lecture des référentiels. |
| `dag`, `chauffeur`, `gestionnaire_transport` | Comptes/profils de QA créés par `QaRoleAccountsSeeder`. Ne pas les présenter comme la liste officielle des rôles de production. |
| Rôles personnalisés | CRUD et synchronisation de permissions disponibles via les routes Roles/Permissions; les droits viennent des permissions effectives, pas du libellé du rôle. |

Dans `User::hasPermissionTo()`, les permissions directes et celles des rôles actifs sont examinées. Un rôle inactif n’accorde pas ses permissions. Les opérations Paramétrage ajoutent le rôle système `super_admin`, l’état actif du compte et `must_change_password=false`.

### Familles de permission (déclarées dans `PermissionSeeder` et `ParametrageSecurity`)

- Utilisateurs : `read_user`, `create_user`, `update_user`, `activate_user`, `deactivate_user`, `reset_user_password`, `assign_role`.
- Rôles/permissions : `read_role`, `create_role`, `update_role`, `delete_role`, `assign_permission`, `read_permission`, `create_permission`, `update_permission`, `delete_permission`.
- Organisation et référentiels : droits `read/create/update/activate/deactivate` pour Directions/Services, droits de lecture/création/modification/suppression/activation pour Types de sortie et Entités géographiques.
- Demandes : `read_demande`, `create_demande`, `update_demande`, `submit_demande`, `cancel_demande`.
- Traitement : `read_demande_traitement`, `read_course`, `create_course`, `update_course`, `confirm_course`, `start_course`, `finish_course`.
- Ressources/Transport : droits `vehicle`, `driver`, `report_course_incident`, `read_transport_signalement`, `read_resource_availability`, `manage_resource_availability`, `confirm_resource_return`.
- Audit et secret : `read_parametrage_audit`, `read_temporary_password`.

Les seeders démontrent les codes et profils techniques, pas les titulaires institutionnels autorisés à les utiliser.

---

## 4. Règles métier par parcours

### 4.1 Authentification et compte

**Routes :** `POST /api/login`, `GET /api/me`, `PUT /api/me/password`, `POST /api/logout`.

- Login public, soumis à limitation de tentatives; vérifie email, hash et statut. Identifiants erronés : 401; compte inactif : 403. Un compte actif reçoit un jeton Sanctum et l’indicateur `must_change_password`.
- Le profil expose l’identité et les rôles/permissions chargés, pas le hash ni la copie chiffrée du temporaire (attributs masqués sur `User`).
- Si le changement de mot de passe est obligatoire, `EnsurePasswordChanged` bloque les endpoints métier; le profil, changement et logout restent accessibles.
- Le mot de passe courant est vérifié lors d’un changement volontaire. Le nouveau doit être confirmé, différent de l’ancien et avoir de 8 à 12 caractères. Le changement retire le temporaire, met `must_change_password=false`, incrémente les générations/version, révoque les anciens jetons et remet un nouveau jeton au demandeur.
- La désactivation et le reset révoquent les tokens existants.
- Le reset génère un temporaire aléatoire de 12 caractères via `PasswordService`; il n’utilise pas le mot de passe fixe `ANINF123`. Le hash sert à l’authentification; la copie chiffrée est conservée temporairement pour révélation.
- La révélation exige permission dédiée, réauthentification de l’administrateur, motif, version de l’utilisateur et génération attendue. Réponse `no-store`; le secret ne doit pas entrer dans l’audit.

**Pourquoi :** invalider rapidement les sessions à risque, obliger le remplacement d’un secret temporaire et éviter que les listes, journaux ou réponses de reset diffusent des mots de passe.

**À aligner :** la limite maximale de 12 caractères est présente dans le code et mérite d’être visible dans OpenAPI et les consignes frontend.

### 4.2 Utilisateurs et sécurité administrateur

**Routes :** `/api/users`, actions d’activation/désactivation, reset, attribution des rôles, révélation du temporaire, historique organisationnel.

- La création exige `direction_id` existant; `service_id` est facultatif. Le Form Request vérifie l’existence, et `ParametrageSecurity` exige que les rattachements nouveaux soient actifs et cohérents. Le compte est actif, `must_change_password=true`, puis reçoit le rôle système `demandeur`.
- `name` Laravel est conservé et synchronisé avec prénom/nom.
- Direction et Service sont des rattachements de compte. Lors d’un changement de Direction, le client doit explicitement fournir le Service compatible ou `null`. Une nouvelle affectation exige Direction active et Service actif dans cette même Direction.
- Un rôle inactif ne peut pas être attribué comme nouveau rôle; ses droits ne sont pas effectifs.
- Désactivation : statut `INACTIF`, révocation des tokens, relations et historiques conservés.
- Les mutations administratives requièrent super-admin actif et permission spécifique; authentification seule ou menu caché ne suffit pas.

**Dernier super-admin :** `ParametrageSecurity::assertAdministratorRemains()` refuse une mutation en 409 `LAST_EFFECTIVE_ADMIN` s’il ne subsiste pas un rôle système super-admin actif, avec son socle de permissions administratives, associé à au moins un utilisateur actif. Un verrou de garde protège les mutations concurrentes.

**Limite :** cela garantit une présence structurelle dans la base, pas la connaissance du mot de passe, la disponibilité d’infrastructure ni l’habilitation institutionnelle réelle du titulaire.

### 4.3 Rôles et permissions

La gestion passe par `/api/roles` et `/api/permissions`. Les affectations sont des opérations distinctes. L’identité système des rôles `super_admin` et `demandeur` est basée sur `system_key`; leur suppression, désactivation, renommage d’identité ou retrait du socle est protégé. Les codes de permissions système ne peuvent pas être supprimés/renommés lorsqu’ils appartiennent aux socles. Un rôle ou une permission encore utilisé ou historiquement référencé n’est pas supprimé silencieusement : réponse de conflit. Les mutations passent par version, idempotence et audit.

**Pourquoi :** un nom d’affichage peut changer; les protections doivent dépendre d’une identité serveur. Le refus de supprimer une relation en usage évite les changements de droits cachés.

### 4.4 Directions et Services

**Routes :** lectures sélectionnables `GET /api/directions`, `GET /api/services`; administration `/api/parametrage/directions`, `/api/parametrage/services`, ainsi que détails, création, modification et activation/désactivation.

- Un Service appartient à une seule Direction.
- Désactiver une Direction ne désactive pas automatiquement ses Services.
- La désactivation conserve les références historiques; le parcours normal ne supprime pas physiquement.
- Les nouveaux rattachements sont limités aux éléments actifs; les éléments historiques restent conservés.
- Les mutations utilisent une version attendue, une clé d’idempotence, une transaction et audit; les changements d’organisation des utilisateurs ajoutent une ligne à `user_organisation_histories`.

**Pourquoi :** éviter qu’un changement d’organigramme efface le sens des demandes ou des rattachements passés sans inventer une hiérarchie absente du modèle.

### 4.5 Types de sortie et entités géographiques

Les routes opérationnelles donnent les références utiles aux formulaires; les routes Paramétrage donnent accès aux vues administratives. Les types et adresses/lieux peuvent être administrés et activés/désactivés. Les éléments inactifs ne doivent pas être proposés aux nouveaux formulaires. Les protections du service de sécurité empêchent des modifications ou suppressions qui détruiraient une référence encore utilisée.

Les données de test/QA ne sont pas des données institutionnelles de production. Aucune Direction, adresse ou donnée administrative réelle ne doit être inventée par un seeder de démo.

### 4.6 Demandes de sortie

**Routes :** `/api/demandes`, détail, mise à jour, soumission, annulation, demande de modification et soumission de la modification.

Une demande comporte notamment demandeur, Direction, Service facultatif, Type de sortie, départ/retour, dates/heures, motif/commentaire, destinations, employés et objets. La direction seule peut suffire au rattachement; le service affine si connu et doit être cohérent avec sa Direction.

Transitions visibles dans `DemandeService` :
- Création : `BROUILLON`, avec référence séquentielle quotidienne.
- Soumission : `BROUILLON → EN_ATTENTE`, uniquement si les champs et collections obligatoires sont présents.
- Édition directe : seulement en `BROUILLON`.
- Annulation : `ANNULEE`, sauf si la demande est déjà `TERMINEE`.
- Modification après validation : création d’un instantané de la demande; à la soumission, `VALIDEE → REVALIDATION_REQUISE`.
- Les changements d’état écrivent dans l’historique.
- Lecture/gestion : propriétaire de la demande ou super-admin actif pour la portée globale.

**Pourquoi :** éviter qu’une demande validée soit réécrite sans trace, et préserver la version initialement examinée. Les permissions de route et la portée sur une demande sont deux contrôles distincts.

### 4.7 Traitement et Courses

**Routes :** `GET /api/traitement/demandes`, `/api/courses`, préparation, confirmation, `/depart`, `/terminer`.

Une préparation regroupe une ou plusieurs demandes en attente, un chauffeur, un véhicule et un plan manuel versionné. La préparation ne réserve rien. La confirmation contrôle la version, demandes toujours en attente, chauffeur/véhicule actifs, période prévue, absence d’indisponibilité/conflit, chargement confirmé manuellement et au moins un arrêt. Elle fait passer la Course à `A_VENIR`, les demandes sélectionnées à `VALIDEE`, et réserve les ressources sur la période déclarée.

Transitions :
- préparation non confirmée → `A_VENIR` par confirmation;
- `A_VENIR → EN_COURS` par le chauffeur affecté;
- `EN_COURS → TERMINEE` par le même chauffeur, avec date de fin réelle et moments séparés de libération du chauffeur et du véhicule.

Une heure estimée ne libère jamais les ressources. La fin de Course ne fait pas automatiquement passer les demandes à `TERMINEE`. Il n’existe pas de calcul automatique de capacité ou d’optimisation d’itinéraire dans les méthodes examinées.

**Pourquoi :** rester honnête sur les capacités de données de la V1 et ne pas libérer une ressource sur une estimation.

### 4.8 Profils Véhicule/Chauffeur, Transport

Profils véhicules/chauffeurs sous `/api/parametrage/vehicules` et `/api/parametrage/chauffeurs`: lecture, création, modification, activation et désactivation. Une affectation exige une ressource active; le Chauffeur doit être lié à un compte actif.

Transport expose signalements, périodes d’indisponibilité et remise en service. Le signalement vient d’un compte attaché à un profil Chauffeur actif; il ne peut signaler que son propre profil. Les événements Transport sont protégés contre modification/suppression ordinaire dans les modèles. Une indisponibilité comporte début, fin estimée et fin effective. Une période clôturée ne peut être rouverte; sa remise en service est explicite et vérifiée. L’heure estimée seule ne réactive pas le véhicule/chauffeur.

OpenAPI cite A01, A07 et A17 comme conditions de mise en service. La visibilité institutionnelle des signalements et l’attribution officielle des droits Transport ne sont donc pas à déduire des seuls rôles QA.

### 4.9 Notifications, historique et audit

Notifications : liste, compteur non lu, marquage individuel/global; certains événements Demande/Course produisent des notifications internes. Aucun envoi mail/SMS/push externe n’est démontré par les routes consultées.

Historisation :
- `user_organisation_histories` : avant/après 
d’un rattachement, auteur, motif, origine, commande et date.
- `organisation_audits` et `parametrage_maintenance_audits` : journal administratif et maintenance.
- Consultations protégées via historique utilisateur et `/api/parametrage/audit`.
- Les mutations Paramétrage insèrent une trace; les refus sensibles peuvent aussi être journalisés. Les événements Transport empêchent la modification/suppression ordinaire.

**À décider :** durée de conservation, règles d’accès détaillées et procédure réglementaire de rectification/archivage ne sont pas définies par le code inspecté.

---

## 5. Protections transversales et erreurs

### Version attendue

Les ressources mutables ont une version de verrouillage; `version_attendue` périmée produit 409 `VERSION_CONFLICT`. Le client doit recharger l’objet et refaire confirmer l’action. **Raison :** éviter qu’une ancienne édition écrase une modification concurrente.

### Idempotence

Une commande utilise une clé UUID `Idempotency-Key`, liée à l’acteur et à une empreinte de l’intention. Même clé/même intention rejoue la réponse; même clé/autre intention renvoie 409 `IDEMPOTENCY_CONFLICT`. Le client réutilise la clé seulement pour retenter la même action après incident réseau. **Raison :** prévenir doublons de création ou de traitement après perte d’une réponse.

### Codes observés
| HTTP | Usage |
|---|---|
| 401 | Authentification ou réauthentification invalide. |
| 403 | Compte inactif, changement de mot de passe requis, permission ou portée refusée. |
| 404 | Identifiant/référence absent ou ressource non accessible selon la route. |
| 409 | Version/idempotence conflictuelle, état métier incompatible, ressource indisponible, référence en usage, dernier administrateur menacé. |
| 422 | Validation des champs, cohérence des références ou chronologie incorrecte. |
| 429 | Limitation de login ou réauthentification. |
| 503 | Garde/stockage indisponible dans certains chemins sécurisés. |

Le format d’erreur n’est pas nécessairement identique dans tous les modules : certaines réponses ont `message` et `code`, d’autres seulement `message`; les erreurs de validation peuvent contenir `errors`. L’intégration doit suivre les schémas d’OpenAPI après réconciliation, pas inventer une enveloppe universelle.

---

## 6. Tableau d’état synthétique

| Domaine | Constat | Règle pratique | Risque / limite |
|---|---|---|---|
| Auth | Sanctum, login limité, profil, logout, changement/reset | Révocation des anciens tokens; changement obligatoire bloque le métier | Max de 12 caractères à aligner au contrat |
| RBAC / utilisateurs | CRUD et garde Paramétrage présents | Admin = super-admin actif + permission + compte conforme | Les titulaires réels restent à confirmer |
| Dernier admin | Garde transactionnelle et contrôle du socle | Refus 409 s’il n’en reste pas un | Ne valide pas que le compte soit opérationnel en production |
| Directions/Services | Relations et activation/désactivation | Services rattachés à une direction; pas de cascade automatique | Référentiel officiel à fournir par l’institution |
| Références | Types et entités géographiques administrables | Inactifs non proposés aux nouveaux usages; préserver les historiques | Vérifier le détail des règles de modification par opération |
| Demandes | Brouillon, soumission, annulation, modification/version | Demande validée modifiée par flux de revalidation | Aucun accès global pour rôle de traitement n’est démontré ici sauf contrôles existants |
| Traitement | Préparation, confirmation, démarrage, fin | Validation manuelle de charge et libération réelle | Pas de calcul
 automatique ni état demande « terminée » par fin Course |
| Ressources/Transport | Profils, signalements, indisponibilités | Ressources actives requises; retour réel explicite | A07/A17 à arbitrer avant usage institutionnel |
| Notifications | Interne, lecture et compteur | Pas de canal externe attesté | Choix de canaux futurs |
| Audit | Journaux consultables et traces | Conserver avant/après; lecture protégée | Rétention et rectification à formaliser |
| OpenAPI | Contrat cible étendu | Lire le statut par opération | Contradiction 99 vs 74/25; conformité exacte non établie ici |

---

## 7. Raisons des choix V1

- **Permissions côté serveur** : les interfaces peuvent différer selon les rôles, mais leur masquage n’est pas une barrière de sécurité.
- **Rôle système par clé stable** : protège les socles même si un nom d’affichage change.
- **Désactivation plutôt que suppression** : conserve les demandes, affectations et historiques.
- **Service facultatif dans une Direction** : permet un rattachement connu à la seule Direction sans exiger une donnée non disponible.
- **Temporaire aléatoire, chiffré transitoirement et révélé sur action explicite** : limite exposition et réutilisation du secret.
- **Version et idempotence** : préviennent respectivement l’écrasement concurrent et le doublon de retry.
- **Affectation et charge manuelles** : évite de prétendre à une capacité ou optimisation que l’application ne calcule pas.
- **Libération réelle attestée** : évite de déclarer libre une ressource encore immobilisée.
- **Audit et historique** : permettent de comprendre après coup qui a changé quoi et sur quelle base.

Ces raisons sont explicatives; elles n’ajoutent pas de règle métier nouvelle.

---

## 8. Décisions à confirmer avant clôture

1. Harmoniser les statuts des 99 opérations OpenAPI (99 marquées implémentées sous `paths`, mais 74 implémentées et 25 partielles sous `x-current-contract`), puis comparer corps, réponses, permissions et codes réels.
2. Confirmer le plafond de mot de passe à 12 caractères et l’indiquer aux intégrateurs.
3. À la création, `direction_id` est obligatoire et `service_id` facultatif. À la mise à jour, le Form Request accepte `direction_id: null`, mais l’effacement réel du rattachement n’est pas démontré par le garde métier; ne pas utiliser cette valeur sans test/clarification.
4. Décider si les responsables de traitement doivent lire globalement les demandes d’une direction/service; le code Demande consulté limite la portée au propriétaire ou au super-admin.
5. Définir le résultat métier d’une demande après une Course terminée, si un tel changement d’état est attendu.
6. Valider officiellement la visibilité des signalements (A07) et qui détient les habilitations Transport (A17).
7. Confirmer les personnes autorisées à être super-admin et la procédure institutionnelle de récupération.
8. Fournir les Directions/Services et rattachements officiels; aucun jeu de test ne doit faire office d’organigramme.
9. Définir conservation, consultation, archivage et rectification des données d’audit.
10. Décider si notifications internes suffisent en V1; aucun envoi externe n’est constaté.
11. Vérifier la configuration réelle de production (HTTPS, CORS, secrets, cache, queue, sauvegardes, reprise); cette revue statique ne la valide pas.

---

## 9. Tests et preuves à consulter

Tests présents dans le dépôt couvrent Auth, Users/Roles/Permissions, Organisation, sécurité/audit Paramétrage, référentiels, Demande, Traitement/Course, Resource Profiles, Transport, Notifications, seeders, OpenAPI et réseau isolé. Les configurations PHPUnit incluent des suites général-safe, Paramétrage/Organisation et réseau. **Aucune suite n’a été relancée pour ce document**, donc aucun nombre de tests/assertions n’est revendiqué comme résultat actuel.

Pour une prochaine validation, noter commande, configuration, base effective, nombre de tests/assertions et résultats. Examiner la configuration de base avant tout lancement; ne pas employer de commande de réinitialisation sur une base conservant des données.

---

## 10. Sources vérifiées

- Routes et middleware : `routes/api.php`, `bootstrap/app.php`, `app/Http/Middleware/EnsurePasswordChanged.php`, `SerializeAuthentication.php`.
- Auth et secrets : `app/Http/Controllers/AuthController.php`, `app/Services/AuthService.php`, `PasswordService.php`.
- RBAC, utilisateurs, sécurité, idempotence/version/audit : `app/Models/User.php`, `app/Models/Role.php`, `app/Services/UserService.php`, `ParametrageSecurity.php`, `ParametrageJournalService.php`.
- Organisation : `app/Models/Direction.php`, `Service.php`, `app/Services/OrganisationService.php`, `OrganisationController.php`.
- Demandes : `app/Models/Demande.php`, `app/Services/DemandeService.php`, Form Requests et Resources associés.
- Traitement : `app/Models/Course.php`, `CoursePreparation.php`, `app/Services/CoursePreparationService.php`.
- Ressources/Transport : `app/Services/ResourceProfileService.php`, `TransportService.php`, modèles Vehicule/Chauffeur/Signalement/Indisponibilité/événement.
- Permissions et jeux d’essai : `database/seeders/PermissionSeeder.php`, `ParametrageSecuritySeeder.php`, `QaRoleAccountsSeeder.php`.
- Contrat et arbitrages Paramétrage : `openapi.yaml`, `docs/SPECIFICATION_MODULE_PARAMETRAGE.md`, `docs/REGISTRE_ARBITRAGES_MODULE_PARAMETRAGE.md`.

## Conclusion pour la réunion

Le backend dispose d’un périmètre fonctionnel étendu : Auth/RBAC, organisation, référentiels, demandes, traitement par Courses, ressources, Transport, notifications et audit. Les choix V1 les plus structurants sont visibles dans le code : contrôle serveur des permissions, protection du socle super-admin, conservation historique, version/idempotence, décision manuelle sur le chargement et libération réelle des ressources.

Le point documentaire prioritaire est l’alignement du contrat OpenAPI avec les routes et le comportement réels. Les arbitrages institutionnels Transport, identité des administrateurs, organigramme officiel et conservation des audits doivent être tranchés avant la clôture de production.





