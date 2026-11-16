# Spécification fonctionnelle et technique — Module Paramétrage ANINF

Date : 27 octobre 2026. Version de conception : V1. Statut : référence pour un développement ultérieur, **non implémentée**.

Le [registre des arbitrages](REGISTRE_ARBITRAGES_MODULE_PARAMETRAGE.md) distingue les décisions métier M01–M10, les choix autonomes C01–C15 et les seules validations institutionnelles I01–I03. Ces identifiants s'appliquent à l'ensemble du présent document.

## 1. Contexte et objectifs

L'API existante gère l'authentification, les utilisateurs, le RBAC et les demandes de sortie. Plusieurs référentiels sont déjà administrables mais ne forment pas encore un module Paramétrage doté des protections demandées. L'objectif est de définir ce module unique sans inventer d'organigramme, sans modifier le workflow métier et sans présenter le futur Traitement / Affectation comme réalisé.

### 1.1 Éléments examinés et niveau de preuve

Références : `docs/AUDIT_MODULE_PARAMETRAGE.md`, `docs/GUIDE_INTEGRATION_FRONT_MOBILE.md`, `docs/MODELE_FORMULAIRE_DEMANDE.md`, documents fonctionnels et techniques Traitement / Affectation présents dans `docs/`, et `openapi.yaml`.

Preuves de code principales :

- `routes/api.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php` : routes, middleware et limite de connexion.
- `app/Http/Controllers/` : `AuthController`, `UserController`, `RoleController`, `PermissionController`, `DirectionController`, `TypeSortieController`, `EntiteGeographiqueController`, `DemandeController` ; leurs Form Requests et API Resources dans `app/Http/`.
- `app/Services/UserService.php`, `PasswordService.php`, `AuthService.php`, `DemandeService.php` : comptes, secrets, jetons, accès aux demandes et transitions.
- `app/Models/User.php`, `Role.php`, `Permission.php`, `Direction.php`, `TypeSortie.php`, `EntiteGeographique.php`, `Demande.php`, `DemandeModification.php` ; migrations de `database/migrations/`.
- `database/seeders/PermissionSeeder.php`, `RoleSeeder.php`, `AdminUserSeeder.php`, `DatabaseSeeder.php`, `TestDataSeeder.php` et commande existante de synchronisation additive du rôle demandeur.
- Tests dans `tests/Feature/`, notamment Auth, User, Role, Permission, Direction, TypeSortie, EntiteGeographique, Demande, Seeders et contrat OpenAPI ; `phpunit.xml` pour l'isolation prévue.

Versions directes constatées : Laravel 13.32.0, Sanctum 4.3.3, Spatie Permission 8.3.0, PHPUnit 12.5.35. Lecture du code et des tests, pas démonstration d'exécution. Aucune base consultée ou modifiée pour cette conception ; aucun test ni commande de déploiement exécuté. Les opérations dynamiques mentionnées ci-dessous sont futures.

### 1.2 Convention de lecture

« Constat » désigne le comportement observé dans les sources ; « cible » un comportement à implémenter. Les recommandations techniques précisent les moyens nécessaires aux garanties ; elles ne constituent pas un plan de livraison ni des migrations prêtes à exécuter.

## 2. Périmètre inclus et exclusions

Inclus : administration des comptes et de leurs rattachements, rôles et permissions, directions et services, types de sortie et lieux, historique organisationnel, audit administratif et protections transversales. Une même navigation Paramétrage regroupe ces domaines.

Exclus : développement du Traitement / Affectation, validation des demandes, véhicules, chauffeurs, indisponibilités, incidents, courses, notifications, import métier massif non contrôlé, délégation de l'administration à d'autres rôles, éditeur technique du serveur et gestion réglementaire automatique des archives. Les données officielles ne sont pas créées à partir du jeu de démonstration.

La gestion des véhicules et des chauffeurs constitue un domaine opérationnel à traiter séparément, pas un référentiel ajouté automatiquement au Paramétrage. L'API Demande ne reçoit pas un nouveau champ service dans cette V1. Les ajustements Demande prévus ici sont strictement ceux nécessaires à l'intégrité et à la conservation des références administrées.

## 3. Acteurs et droits d'accès

### 3.1 Règle serveur

Accès administratif : authentification Sanctum valide **et** compte `ACTIF` **et** `must_change_password=false` **et** rôle système `super_admin` actif **et** permission effective de l'opération. Contrôle sur chaque requête et dans les services d'écriture, pas seulement dans le menu. Le rôle système est identifié par une identité stable gérée par le serveur, non par un nom libre fourni par le client.

Un utilisateur inactif n'accède plus aux API protégées ; ses jetons sont révoqués. Un utilisateur devant changer son mot de passe conserve uniquement les opérations Auth prévues : `/api/me`, `/api/me/password`, `/api/logout` ; `/api/login` reste public. L'exception logout conserve la possibilité de fermer une session contrainte et ne donne aucun accès métier.

### 3.2 Lectures opérationnelles et administration

Les GET existants `/api/directions`, `/api/types-sortie`, `/api/entites-geographiques` restent accessibles aux utilisateurs ayant respectivement `read_direction`, `read_type_sortie`, `read_entite_geographique`, après les contrôles de compte et mot de passe. Ils retournent uniquement les références sélectionnables et les champs utiles au formulaire. Ils n'ouvrent ni les écrans Paramétrage, ni les archives, ni les opérations d'administration.

Les listes administratives complètes utilisent `GET /api/parametrage/directions`, `/api/parametrage/services`, `/api/parametrage/types-sortie`, `/api/parametrage/entites-geographiques`, avec accès exclusivement `super_admin` et permission de lecture correspondante. La sélection d'un service dans la gestion des comptes utilise la liste administrative filtrée par direction ; aucune lecture opérationnelle supplémentaire de services ni auto-modification du rattachement n'est ajoutée en V1.

Les chemins administratifs existants utilisateurs/RBAC et les écritures types/lieux sont conservés et durcis ; aucune seconde version parallèle de ces opérations. Les anciennes lectures par ID de type/lieu sont réservées à l'administration ; une demande reste lisible avec ses références historiques par sa propre ressource, sans ouvrir une consultation arbitraire d'archives aux demandeurs.

### 3.3 Matrice cible

| Domaine | Permissions conservées ou ajoutées | Condition supplémentaire |
| --- | --- | --- |
| Comptes | `read_user`, `create_user`, `update_user`, `activate_user`, `deactivate_user`, `reset_user_password`, `assign_role` | `super_admin` actif ; contrôles du dernier administrateur |
| Secret temporaire | nouvelle `read_temporary_password` | Opération dédiée, réauthentification, motif, cible et génération attendue |
| Rôles | `read_role`, `create_role`, `update_role`, `delete_role`, `assign_permission` | Identités et socles système protégés |
| Permissions | `read_permission`, `create_permission`, `update_permission`, `delete_permission` | Codes applicatifs protégés |
| Directions | `read_direction` ; nouvelles `create_direction`, `update_direction`, `activate_direction`, `deactivate_direction` | Pas de suppression |
| Services | nouvelles `read_service`, `create_service`, `update_service`, `activate_service`, `deactivate_service` | Parent obligatoire, immuable |
| Types de sortie | quatre permissions existantes `read_type_sortie`, `create_type_sortie`, `update_type_sortie`, `delete_type_sortie` ; nouvelles `activate_type_sortie`, `deactivate_type_sortie` | Contenu figé après usage |
| Lieux | quatre permissions existantes `read_entite_geographique`, `create_entite_geographique`, `update_entite_geographique`, `delete_entite_geographique` ; nouvelles `activate_entite_geographique`, `deactivate_entite_geographique` | Contenu figé après usage |
| Historique organisationnel | `read_user` | Consultation administrative du compte |
| Audit | nouvelle `read_parametrage_audit` | Lecture seule, sans suppression ni export massif V1 |

Ces nouveaux codes constituent le socle administratif cible. Leur ajout ne donne pas de pouvoir à un rôle ordinaire : le contrôle `super_admin` demeure obligatoire. Aucun `Gate::before` accordant indistinctement toutes les fonctions métier n'est prévu.

## 4. Organisation fonctionnelle des écrans

Une entrée Paramétrage, invisible aux autres utilisateurs, contient : Utilisateurs ; Rôles ; Permissions ; Organisation avec Directions et Services ; Référentiels avec Types de sortie et Entités géographiques ; Journal d'audit. Aucun écran vide de paramètres généraux n'est requis.

Chaque liste administrative offre recherche, filtres pertinents (statut, direction pour services/utilisateurs), pagination et détail. Valeur par défaut de pagination : 20 ; `per_page` entier de 1 à 100, sinon 422. Les listes administratives ne sont pas des extractions illimitées. Les formulaires n'exposent que les champs modifiables ; les champs figés sont affichés comme tels avec explication.

Le détail utilisateur présente identité, statut, direction/service actuels, rôles attribués avec leurs statuts, droits effectifs et historique. Actions séparées : modifier, activer/désactiver, gérer les rôles, réinitialiser, révéler le temporaire si disponible. Jamais de secret chargé automatiquement à l'ouverture du détail.

Les confirmations indiquent l'objet, les effets et un motif obligatoire pour désactivation, réactivation, rattachement, changement de rôle/permission, reset, révélation et suppression autorisée. Un conflit de version provoque un rechargement et une nouvelle confirmation explicite, pas une réémission aveugle. Après perte de ses propres droits, l'interface quitte l'administration ; le serveur reste l'autorité.

## 5. Gestion des utilisateurs

### 5.1 Création et consultation

Constat : création sans rôle automatique, direction nullable, secret encore disponible dans certaines réponses. Cible : nom, prénom et email requis ; direction obligatoire ; service facultatif et cohérent ; statut initial `ACTIF`, `must_change_password=true`, rôle système `demandeur` attribué dans la même transaction. Un échec d'attribution, d'audit ou de validation annule toute la création.

Le champ Laravel historique `name` reste synchronisé selon la convention actuelle ; il n'est ni supprimé ni rendu éditable séparément. Le serveur ignore ou rejette les tentatives d'injection de rôles, permissions, identité système, hash ou statut de changement de mot de passe dans le formulaire de création. La gestion des rôles est une opération distincte après création.

Le détail et les listes ne contiennent jamais `temporary_password`, hash, copie chiffrée ni jeton. Ils peuvent indiquer `temporary_password_available` et `must_change_password`, sans prétendre connaître un secret non conservé. Le détail administratif peut afficher les permissions directes historiques séparément des permissions héritées actives.

### 5.2 Modification et rattachement

Profil : modification contrôlée des champs existants, email unique, maintien de l'historique métier. Rattachement : direction obligatoire et service nullable ; les deux sont évalués ensemble dans une opération transactionnelle. Changer de direction avec un ancien service impose de fournir explicitement un nouveau service compatible ou `null` ; aucune correction silencieuse.

Une structure inactive déjà attachée peut rester inchangée lors d'une correction de nom/email. Une nouvelle direction ou un nouveau service doit être sélectionnable au moment de l'écriture. La réactivation d'un compte ne constitue pas à elle seule un nouveau rattachement : elle peut conserver son organisation inactive, signalée à l'administrateur.

### 5.3 Activation et désactivation

Désactivation : `INACTIF`, révocation de tous les jetons, conservation des rôles, rattachements, demandes et historiques. Contrôle du dernier administrateur avant validation. Réactivation : `ACTIF`, mêmes rôles ; les rôles restés inactifs ne contribuent toujours pas aux droits. Aucun jeton n'est recréé automatiquement : nouvelle connexion nécessaire.

Les répétitions d'un même changement de statut n'ajoutent pas une seconde transition ; la réponse indique l'état déjà atteint. Il n'y a pas de suppression de compte dans ce périmètre.

### 5.4 Mots de passe et secrets temporaires

Conserver les règles actuelles vérifiées dans `PasswordService` : nouveau mot de passe de 8 à 12 caractères, confirmation et différence avec l'ancien ; temporaire aléatoire de 12 caractères. **Le secret fixe historique ANINF123 n'est pas réintroduit.** Le compte initial conserve le mécanisme d'installation sécurisé actuel, adapté au rattachement obligatoire, sans valeur officielle fabriquée.

Création et reset génèrent le temporaire, conservent son hash pour l'authentification et une copie chiffrée uniquement tant que nécessaire à la révélation ; `must_change_password=true`. Le reset révoque tous les jetons, incrémente une génération de secret et ne restitue pas le secret dans sa réponse. L'administrateur se réauthentifie pour effectuer un reset et pour révéler le temporaire. Le mot de passe de réauthentification est celui de l'acteur, jamais celui de la cible.

La révélation exige `read_temporary_password`, le rôle administratif, le mot de passe actuel de l'acteur, un motif, la version du compte et la génération de secret attendue. Elle échoue si le temporaire a déjà été remplacé, n'est pas disponible ou si l'acteur n'a plus ses droits. Sa lecture produit un événement d'audit sans valeur secrète. La réponse utilise `Cache-Control: no-store` et n'est jamais conservée dans un registre de rejeu. Limiter les tentatives de réauthentification ; ne jamais journaliser le corps de cette requête.

Après changement réussi par l'utilisateur : `must_change_password=false`, suppression de la copie chiffrée, révocation des anciens jetons puis émission d'un nouveau jeton dans une opération cohérente. Un reset concurrent ne doit pas laisser un jeton de session émis après sa révocation. Le logout conserve sa sémantique de révocation de la session courante.

## 6. Gestion des rôles et permissions

### 6.1 Rôles système

`super_admin` et `demandeur` disposent d'une identité système serveur stable indépendante du libellé. Nom technique et guard non modifiables, suppression et désactivation interdites dans le module. Description modifiable ; statut propre maintenu `ACTIF`. Ces exceptions explicites à la désactivation des rôles ordinaires assurent la disponibilité du socle administratif et du rôle automatiquement attribué.

Socle `demandeur` protégé : `read_demande`, `create_demande`, `update_demande`, `submit_demande`, `cancel_demande`, `read_direction`, `read_type_sortie`, `read_entite_geographique`. Les droits administratifs ne lui sont pas accordés. Les éventuels autres droits métier déjà attribués sont examinés sans effacement silencieux ; l'ajout de droits ordinaires reste possible sous contrôle.

Socle `super_admin` : toutes les permissions administratives de la section 3.3, protégées du retrait. Les permissions Demande et des futurs modules ne lui sont pas automatiquement accordées du seul fait de sa fonction technique. Les droits métier actuellement présents ne sont pas retirés par une simple migration de Paramétrage ; leur modification ultérieure demeure explicite et auditée.

### 6.2 Rôles ordinaires et attributions

Nom technique unique dans le guard, description et statut `ACTIF`/`INACTIF`. Création, modification et réactivation permises. Un rôle peut avoir zéro permission ; un utilisateur peut avoir zéro rôle si ce n'est pas le dernier administrateur effectif. La connexion et les opérations sur son propre mot de passe ne dépendent pas de posséder `demandeur`.

Le remplacement de rôles accepte une liste d'identifiants distincts, y compris `[]`. Tous les identifiants doivent exister ; un rôle inactif ne peut être ajouté. Un rôle inactif déjà possédé peut être conservé ou retiré, sans être réattribué comme nouveau rôle. Aucun changement partiel si une valeur est invalide. Attribution de `super_admin` : contrôle du socle, réauthentification de l'acteur et audit explicite. Tous les chemins de retrait restent soumis au garde du dernier administrateur.

La suppression d'un rôle ordinaire échoue avec 409 s'il possède des utilisateurs, des permissions ou une dépendance historique nécessitant sa conservation. Ne pas détacher silencieusement les pivots. La désactivation constitue l'alternative à la suppression.

### 6.3 Permissions

Les 30 codes actuels employés par l'application et tous les nouveaux codes applicatifs sont identifiés comme protégés. Pas de renommage ni suppression par API, même s'ils sont momentanément non attribués. Libellé et description restent modifiables et audités. L'identité protégée n'est pas un booléen contrôlable par le client.

Une permission personnalisée peut être créée ; cela n'ajoute aucune route ni action métier. Son code peut être corrigé seulement s'il n'est ni applicatif, ni attribué, ni référencé par l'historique ; sinon 409 et conservation de l'ancien code. Sa suppression exige absence de relation avec tout rôle, utilisateur direct et dépendance historique. Le remplacement des permissions d'un rôle ordinaire accepte `[]` ; pour un rôle système, retirer un élément du socle retourne 409 et ne produit aucun retrait partiel.

### 6.4 Droits effectifs

À chaque contrôle, calculer l'union des permissions provenant des rôles **actifs** et des permissions directes historiques encore admises. Les attributions directes ne sont pas ajoutées ni éditées dans la V1. Une permission présente via un autre rôle actif reste effective lorsqu'un rôle est désactivé : on suspend sa contribution, pas la permission globalement.

La même règle s'applique aux middleware, policies/Gates, appels `can`, services et projections `/me`. Les exceptions métier fondées sur `hasRole('super_admin')` doivent reconnaître l'identité et le statut système, pas un simple nom. L'API peut exposer les rôles attribués inactifs pour information, mais ne doit pas présenter leurs droits comme effectifs. Invalidation du cache et des relations déjà chargées nécessaire ; un TTL seul ne satisfait pas « immédiatement ».

## 7. Gestion des directions et services

Direction : identifiant, nom, code, description facultative, statut, dates et version. Service : mêmes informations et `direction_id` obligatoire. Code direction unique globalement ; code service unique dans sa direction ; codes immuables après création. Le parent d'un service est immuable dans cette V1 : créer un service sous la nouvelle direction puis rattacher explicitement les utilisateurs si l'organisation change. Aucun transfert collectif implicite.

Création active par défaut ; modification de nom/description autorisée et auditée ; activation/désactivation explicite. Les anciens noms restent dans les instantanés historiques. Aucun DELETE ordinaire ni suppression par cascade.

Sélectionnabilité d'un service = service actif **et** direction parente active. Désactiver une direction ne modifie aucun statut de service, compte ou rôle. Réactiver la direction rend de nouveau sélectionnables les services dont le statut propre est resté actif ; ceux volontairement inactifs le restent.

Une direction peut être désactivée avec des utilisateurs et services rattachés : les liens sont conservés et les listes signalent l'état hérité. Un changement de rattachement ne peut jamais produire un service d'une autre direction, y compris avec deux opérations concurrentes.

## 8. Gestion des types de sortie

Constat : CRUD, code unique et refus de suppression si référencé par demandes ou modifications ; absence de statut et de gel historique.

Cible : `ACTIF`/`INACTIF`, activation/désactivation et consultation des archives dans l'administration. Code technique immuable. Le libellé peut être corrigé tant que le type n'a jamais été utilisé ; à partir de sa première utilisation, le contenu descriptif est figé. Une correction s'effectue par création explicite d'une nouvelle référence et désactivation éventuelle de l'ancienne, sans remplacement automatique dans les demandes.

« Utilisé » comprend toute demande ou proposition de modification enregistrée, même en brouillon. Une marque de première utilisation conservée empêche qu'une suppression ultérieure de copie rende la référence modifiable de nouveau. La suppression physique n'est autorisée que pour une référence jamais utilisée, sans dépendance, et laisse un événement d'audit. Sinon 409. Aucune liste métier officielle ou type par défaut n'est inventé.

Une désactivation ne supprime pas les demandes passées. Les règles de sélection et de soumission sont précisées en section 11.

## 9. Gestion des entités géographiques

Constat : adresse, coordonnées facultatives et description ; absence de bornes géographiques dans les validations actuelles ; dépendances à des FK et à des destinations de copie stockées en JSON.

Cible : même politique de statut, première utilisation, gel et suppression que les types de sortie. Le contenu gelé comprend adresse, latitude, longitude et description afin de conserver le lieu effectivement choisi ; même une correction descriptive se fait par une nouvelle référence après usage. Pas de géocodage automatique, de coordonnées devinées ni de fusion automatique d'homonymes.

Adresse obligatoire, 255 caractères maximum ; deux lieux peuvent partager une adresse : l'identifiant reste la référence. Latitude entre -90 et 90, longitude entre -180 et 180, chacune nullable selon le contrat existant ; au plus sept décimales, sinon 422 sans arrondi silencieux. Le stockage reste compatible avec les décimaux existants et les réponses conservent des coordonnées en chaînes décimales à sept positions lorsqu'elles sont présentes.

Les contrôles de dépendance couvrent départ, retour, destinations, copies et instantanés historiques. Une simple FK ne couvre pas une liste JSON : l'enregistrement d'une telle référence participe au protocole transactionnel commun. Une référence utilisée ne doit pas disparaître entre validation et écriture d'une demande.

## 10. Gestion des paramètres généraux retenus

Aucun nouveau réglage métier modifiable n'est nécessaire à la V1 sur la base des preuves actuelles. Il n'est donc prévu ni table générique de paramètres, ni écran de modification de configuration, ni endpoint de réglage technique.

Restent définis par le contrat ou le code : règles de mot de passe actuelles, pagination plafonnée à 100, statuts Demande et transitions, format des références, limite journalière actuelle et configuration du fuseau. Les valeurs du futur Traitement / Affectation déjà arbitrées, notamment les évolutions de fuseau ou de capacité, ne sont pas appliquées par cette spécification. Une page d'aide peut expliquer les règles réellement en vigueur sans exposer `.env`, clés, identifiants de connexion ou configuration d'infrastructure.

## 11. Règles métier et validations

### 11.1 Contrats de saisie

| Objet | Règle cible |
| --- | --- |
| Identité utilisateur | Nom/prénom requis, limites actuelles de 255 ; email valide et unique ; `name` dérivé, non édité indépendamment |
| Organisation utilisateur | Direction obligatoire ; service facultatif de cette direction ; vérification applicative et contrainte structurelle après reprise |
| Direction/service | Nom et code requis, maximum 255 ; espaces périphériques retirés ; comparaison des codes insensible à la casse ; unicité du code dans son périmètre ; description facultative selon les limites documentées du contrat |
| Codes techniques | Création selon les conventions actuelles du projet ; pas de normalisation destructive des codes existants ; conflit de casse détecté avant ajout d'unicité ; direction/service/type immuables après création |
| Rôle/permission | Nom technique requis, maximum 255 et unicité dans le guard ; identifiants distincts ; listes vides autorisées sous les gardes décrits ; pas d'entrée client pour identité système |
| Motif administratif | Requis pour les actions sensibles listées en section 4 ; texte de 1 à 1 000 caractères après trim, sans secret |
| Types | Code et libellé requis, maximum 255 ; unicité du code ; gel après usage |
| Lieux | Adresse requise, maximum 255 ; coordonnées facultatives bornées et limitées à sept décimales ; pas d'unicité artificielle d'adresse |
| Pagination | Entier 1 à 100, défaut 20 ; valeurs hors limites ou invalides : 422 |
| Concurrence | Version attendue entière sur mutation d'un objet existant ; clé d'idempotence sur commande administrative mutante |

Conserver les limites existantes des descriptions lorsqu'elles sont déjà définies ; lorsqu'un champ nouveau n'a pas de limite antérieure, retenir 1 000 caractères pour une description facultative. Le futur OpenAPI doit expliciter ces limites, y compris les descriptions existantes non limitées, sans leur inventer une borne rétroactive.

### 11.2 Demandes et références inactives

Les champs et règles Demande actuels restent en place : direction déclarée, type, départ, motif, dates/heures, destinations et employés, avec les particularités existantes dont `objets: null`. Le service organisationnel n'est pas ajouté au formulaire Demande et la direction déclarée n'est pas automatiquement remplacée par celle du compte.

- Nouvelle sélection ou remplacement : la direction, le type et chaque lieu choisi doivent être actifs. Un service choisi doit aussi avoir une direction active.
- Consultation et modification d'un champ sans rapport : conserver une référence historique inactive inchangée est permis ; le client la présente comme ancienne valeur, non comme nouvelle option sélectionnable.
- Soumission de brouillon ou soumission d'une proposition de modification : toutes les références utilisées par cette nouvelle soumission doivent être actives. Sinon 409 `REFERENCE_INACTIVE`, avec champs à corriger. L'utilisateur peut corriger son brouillon avant de soumettre.
- Une désactivation de référentiel ne change pas le statut d'une demande déjà soumise/validée, ne crée pas de revalidation automatique et n'efface pas les copies. Les règles opérationnelles ultérieures restent celles du module concerné.

L'évaluation finale s'effectue dans la transaction, après les verrous : une validation HTTP préalable n'est pas une garantie d'intégrité.

## 12. Historique des rattachements

Chaque changement effectif de direction ou de service produit une entrée append-only : utilisateur, ancienne et nouvelle direction, ancien et nouveau service, instantanés des codes/noms, date serveur d'effet, auteur et motif, origine et identifiant de commande/corrélation. La première entrée représente l'état initial ; une réémission identique ne crée pas de faux mouvement.

Les périodes sont déductibles des événements ordonnés : état valable depuis l'événement jusqu'au suivant. Pour la reprise d'un utilisateur existant, enregistrer « état observé lors de la reprise », date d'observation et provenance ; ne pas affirmer une ancienneté inconnue. Les dates futures ou rétroactives de rattachement ne sont pas gérées en V1.

L'écriture du compte et de son événement historique appartient à la même transaction. Une désactivation de compte ou de structure ne clôt pas artificiellement son rattachement. Le renommage ultérieur d'une structure ne modifie pas les anciens instantanés.

Les demandes gardent leur propre direction déclarée et leurs références ; elles ne suivent pas les changements du compte. Pour les nouvelles écritures, des instantanés des libellés utiles à la lecture historique doivent accompagner ces références. Pour les anciennes, la reprise est explicitement datée et qualifiée : elle ne restitue pas un ancien libellé déjà écrasé.

## 13. Journal d'audit

Événements : création/modification de compte ; activation/désactivation ; rattachement ; remplacement/ajout/retrait de rôles ; changement de permissions et statut des rôles ; reset ; révélation de temporaire ; création/modification/statut/suppression autorisée de référentiels. Inclure les modifications faites par commandes de maintenance et installations contrôlées, avec une identité d'exécution explicite, pas un faux utilisateur humain.

Champs : identifiant, date serveur, auteur ou origine technique contrôlée, action, type et identifiant de cible, résultat, motif, identifiant de corrélation, différences avant/après autorisées, version de schéma. Les suppressions autorisées laissent un libellé de cible compréhensible sans FK destructrice. Les données d'organisation historiques sont conservées même si les libellés courants changent.

Liste blanche des différences, pas sérialisation complète d'un modèle ou du corps HTTP. **Exclure** mot de passe courant/temporaire, hash de mot de passe, copie chiffrée, jeton, cookie, en-tête Authorization, clé de chiffrement, données d'environnement et secret de réauthentification. Un reset journalise son résultat et sa génération non secrète, jamais sa valeur. Ne pas demander de saisir un secret dans le motif.

Mutation réussie, historique, audit et résultat d'idempotence sont validés atomiquement. Si l'audit obligatoire est indisponible, refuser l'opération sensible sans changement partiel ; aucune révélation de secret sans trace. Les refus de sécurité significatifs ont une trace distincte après annulation de la mutation, sans conserver son payload ; ne pas prétendre qu'une entrée écrite dans une transaction annulée survivrait.

Consultation par `super_admin` avec `read_parametrage_audit`, filtres date/action/auteur/cible et pagination. Aucun PATCH/DELETE ordinaire ni purge automatique. L'append-only applicatif ne protège pas d'un administrateur de base : cloisonnement des droits d'exploitation et sauvegardes sont des précautions nécessaires, non une inviolabilité démontrée. Conservation institutionnelle : I03.

## 14. Sécurité, confidentialité et protection du dernier administrateur

### 14.1 Définition et invariants

Administrateur effectif : utilisateur actif portant l'identité système `super_admin` active et dont le socle administratif protégé est utilisable par les contrôles serveur. Après la transition de données, il possède une direction valide ; le statut inactif d'une direction ne désactive pas le compte. `must_change_password=true` ne le retire pas du décompte, car il peut recouvrer son accès en changeant son mot de passe via Auth. Cette définition ne garantit ni qu'une personne connaît encore son secret ni la disponibilité de son poste : le secours institutionnel relève de I02.

Toute mutation doit laisser au moins un administrateur effectif. Protéger notamment désactivation du compte, remplacement de ses rôles, modification/suppression/renommage/statut du rôle système et retrait de ses permissions. Les protections d'identité/socle interdisent certaines opérations même s'il existe plusieurs administrateurs. L'auto-désactivation ou l'auto-retrait de `super_admin` n'est autorisé que si un autre administrateur effectif demeure ; aucune dérogation parce que l'acteur est lui-même administrateur.

### 14.2 Protocole transactionnel cible

La garantie exige un point commun de sérialisation, pas deux comptages indépendants. Retenir en V1 un enregistrement de garde stable de sécurité :

1. Commencer une transaction ; prendre son verrou exclusif pour une mutation Paramétrage ou de credentials. Les écritures métier qui consomment une référence administrée prennent un verrou partagé sur cette même garde avant leurs autres verrous et le conservent jusqu'au commit.
2. Relire l'acteur, son statut et ses droits effectifs dans l'état courant ; vérifier la réauthentification lorsqu'exigée. Aucun droit périmé issu d'une relation Eloquent préchargée ne suffit.
3. Vérifier/enregistrer la commande d'idempotence, puis verrouiller les objets concernés selon un ordre stable ; vérifier les versions, dépendances et références.
4. Calculer les effets et le nombre d'administrateurs effectifs après mutation ; si un invariant échoue, annuler sans détachement partiel.
5. Écrire mutation, historique et audit, résultat non secret de commande ; valider la transaction et invalider les projections de permissions appropriées.

Ordre commun : garde globale, commande, racines métier concernées par type/identifiant stable, références/organisation par identifiant, pivots et événements. Une opération ne prend pas un verrou partagé pour ensuite le promouvoir en exclusif. Toute voie existante doit être adaptée au même ordre : API, jobs éventuels, seeders de sécurité et commandes de maintenance. Une écriture SQL externe qui contourne ce protocole n'est pas couverte par la garantie applicative.

Le verrou partagé des écritures Demande permet d'ordonner une sélection avec une désactivation/suppression de référence. Si la désactivation gagne, l'écriture voit le nouveau statut et refuse ; si l'écriture gagne, l'utilisation est enregistrée avant la désactivation et reste historique. La marque de première utilisation est écrite sous verrou sur la référence concernée.

Connexion/émission de jeton, changement et reset de mot de passe, désactivation et révocation participent au protocole commun et relisent le compte verrouillé. L'émission de jeton ne peut se faire tardivement à partir d'une authentification validée avant un reset ou une désactivation. Les nouvelles requêtes après commit d'une suspension de rôle doivent utiliser les droits actualisés ; une requête d'écriture déjà en cours est ordonnée par le protocole. Ce n'est pas une annulation rétroactive de toute lecture déjà envoyée au client.

Détecter les interblocages et autoriser des reprises bornées de transaction ; aucune notification ou émission de secret externe avant commit. Si les reprises échouent : réponse temporaire contrôlée, sans succès annoncé. Les performances et les verrous réels doivent être prouvés sur MySQL ; le verrou global privilégie la simplicité de sûreté pour le faible volume administratif V1, sans revendiquer une capacité non mesurée.

### 14.3 Doubles soumissions et versions

Chaque commande administrative mutante exige un `Idempotency-Key` UUID et, pour une cible existante, une version attendue. Après authentification et autorisation actuelles, une même clé avec la même intention restitue le résultat non secret déjà validé, sans nouvelle mutation ni nouvel événement de réussite. Une même clé avec une intention différente retourne 409. Comparer une intention canonique métier, pas un corps brut incluant un mot de passe. Ne conserver ni secret ni son empreinte dans le registre de commandes.

Un résultat reconnu comme rejeu doit être identifié avant de rejeter sa propre ancienne version ; aucune répétition autorisée si l'acteur a perdu son accès. Une version périmée pour une nouvelle commande retourne 409 ; ne pas écraser une modification concurrente. Contraintes uniques en base pour codes et commandes : l'unicité ne doit pas dépendre d'une seule requête de validation.

Création et reset ne retournant pas de secret, leurs replays restent non secrets. La révélation n'est pas rejouée depuis un cache : chaque lecture refait les contrôles et produit sa propre trace. Le premier succès d'une auto-désactivation peut être suivi d'un 401 au rejeu puisque le jeton est révoqué ; ne jamais réactiver l'authentification pour retrouver une ancienne réponse.

V1 : pas de purge automatique du registre de commandes. Un futur mécanisme d'expiration devra définir sa fenêtre de garantie et rester cohérent avec I03 ; ne pas promettre une déduplication éternelle après suppression des preuves.

### 14.4 Confidentialité et protections complémentaires

TLS en exploitation ; chiffrement du temporaire via les mécanismes de l'application ; clé tenue hors dépôt ; réponses secrètes non mises en cache ; corps de requêtes sensibles et réponses exclus des logs HTTP/APM. Conserver la limitation de tentatives de login existante et ajouter une limite sur réauthentification/révélation. Les tentatives échouées ne divulguent pas si un secret temporaire existe à un utilisateur non autorisé.

Les droits sont contrôlés par rôle, permission et état courant, pas seulement à la création du token. La suspension d'un rôle ne nécessite pas de détruire toutes les sessions si les permissions sont réellement recalculées ; la désactivation de compte et les opérations de mot de passe révoquent les tokens selon leurs règles propres.

## 15. Comportement en cas d'erreur et de conflit

Préserver l'enveloppe de réponse de l'API actuelle (`message`, `data` quand pertinent et `errors` de validation). Les codes d'erreur métier stables ci-dessous sont des extensions du **futur** contrat OpenAPI à documenter, pas des champs supposés déjà présents. Aucune trace technique ni secret dans une erreur.

| HTTP | Cas cible |
| --- | --- |
| 200 / 201 | Lecture/modification ou création validée ; informations non secrètes |
| 204 | Suppression autorisée d'un élément jamais utilisé, sans corps |
| 401 | Token absent, invalide ou révoqué ; échec d'authentification/réauthentification selon convention Auth |
| 403 | Compte inactif, changement obligatoire hors exceptions Auth, rôle administratif absent ou permission insuffisante |
| 404 | Cible absente ou identifiant de référence inexistant fourni pour une attribution ; priorité aux contrôles d'accès avant recherche sensible |
| 409 | `LAST_EFFECTIVE_ADMIN`, `SYSTEM_OBJECT_PROTECTED`, `REFERENCE_IN_USE`, `REFERENCE_INACTIVE` lors de soumission ou concurrence, `VERSION_CONFLICT`, `IDEMPOTENCY_CONFLICT`, temporaire indisponible ou génération changée |
| 422 | Champs invalides, pagination hors limites, clé/version obligatoire absente ou mal formée, email/code déjà pris, nouveau rôle inactif, incohérence service/direction ou nouvelle structure inactive détectée à la validation |
| 429 | Limitation de tentatives login ou opération de réauthentification |
| 503 | Indisponibilité transactionnelle/audit obligatoire ou échec temporaire après reprises bornées, aucune mutation partielle |

Si une référence valide à la validation devient inactive avant l'écriture finale, répondre 409 ; si elle était déjà invalide dans le formulaire, 422. Le contrôle final sous verrou décide. Une liste contenant un identifiant inexistant échoue entièrement avec 404 ; ne pas ignorer les valeurs inconnues.

Une commande validée mais dont la réponse réseau est perdue doit être récupérable par sa clé, sous autorisation actuelle. L'interface ne confond pas délai d'attente et échec certain.

## 16. Modèle de données conceptuel et relations attendues

| Entité | Champs/relations structurants cibles |
| --- | --- |
| Direction | Existant conservé : id, nom, code, description, statut, timestamps ; version ajoutée ; plusieurs services et utilisateurs ; références Demande préservées |
| Service organisationnel | id, direction_id obligatoire, nom, code, description, statut, timestamps, version ; parent immuable ; unicité `(direction_id, code)` |
| User | Champs existants conservés ; direction_id obligatoire après reprise ; service_id nullable ; version et génération de secret ; rôles multiples ; historique multiple |
| Role | Spatie conservé ; identité système nullable stable, socle reconnu côté serveur, statut et version ; pivots existants conservés |
| Permission | Spatie conservé ; classification applicative protégée, code/guard, libellé/description et version ; relations rôles et anciennes attributions directes |
| Historique rattachement | user, avant/après direction/service et instantanés, auteur/origine, date d'effet/observation, motif, commande ; append-only |
| TypeSortie | Existant + statut, version et marque monotone de première utilisation ; aucune suppression historique |
| EntiteGeographique | Existant + statut, version et marque de première utilisation ; toutes dépendances vérifiées, y compris JSON |
| Instantanés Demande | Libellés/références nécessaires à l'histoire, provenance et date de capture ; pas de fausse reconstitution ancienne |
| Audit administratif | Événement structuré non secret, auteur, action, cible, différences autorisées, date, corrélation, résultat |
| Commande idempotente | Identité acteur/action/cible, clé protégée, intention canonique non secrète, statut et résultat minimal non secret, dates ; contrainte unique |
| Garde sécurité | Enregistrement stable servant de point de verrouillage commun ; pas un paramètre éditable |

Les noms des nouvelles tables et classes restent à aligner sur les conventions du dépôt lors de l'implémentation ; le modèle décrit les contraintes, pas une nouvelle architecture parallèle.

Intégrité : FK utilisateur-direction restrictive après rapprochement ; FK service-direction restrictive ; relation utilisateur-service compatible avec sa direction, idéalement contrainte composite `(service_id, direction_id)` vers une clé unique correspondante du service. Le cas `service_id=null` reste permis ; les vérifications applicatives restent nécessaires pour les statuts et messages. Pas de cascade supprimant utilisateurs, demandes, audit ou historique. Les références d'audit d'un objet supprimable conservent une identité lisible et ne dépendent pas d'une FK avec cascade.

La marque de première utilisation ne disparaît pas avec une copie. Lors de la reprise, les usages existants sont recensés ; si leur première date réelle n'est pas fiable, conserver une date d'observation et cette provenance, sans fabriquer un instant historique exact.

## 17. Impacts identifiés sur l'existant

| Source actuelle | Constat vérifié | Impact cible, non réalisé ici |
| --- | --- | --- |
| `routes/api.php` | 44 routes applicatives, administration autorisée par permissions seules | Ajouter le garde administratif sur tous les chemins concernés ; routes nouvelles ciblées ; ne pas promettre un maintien à 44 routes |
| `UserService` / `UserController::show` / `UserResource` | Pas de rôle automatique, rattachement nullable ; secret visible dans certains détails | Création transactionnelle, organisation, suppression des secrets des réponses ordinaires et endpoint dédié |
| `PasswordService` / `AuthService` / `AuthController` | Temporaires aléatoires ; révocations existantes mais émission de token distincte de certaines mutations | Conserver les règles de mot de passe ; unifier la section critique credentials/jetons |
| `RoleController` / `PermissionController` / modèles Spatie | Statut ne filtre pas tous les droits ; objets système non protégés ; listes non vides | Évaluation active commune, identités/socles, listes vides sous garde, dépendances directes et historiques |
| `DirectionController` et migrations User | Liste seulement, `direction_id` nullable sans FK | Administration complète sans DELETE ; services ; migration additive en plusieurs étapes |
| Types/lieux et Form Requests | CRUD sans statut ; contenu modifiable après utilisation | Statut, gel, contrôle d'usage monotone et bornes géographiques |
| `DemandeService` / ressources | Verrous métier existants, relations affichées en direct, copies avec JSON | Participer au protocole commun ; instantanés et contrôle des références sans ajouter le workflow Traitement |
| Seeders de sécurité | `super_admin` reçoit toutes les permissions ; administrateur existant peut voir ses rôles remplacés | Synchronisations additives contrôlées, socles protégés, pas de remplacement global ni données de test |
| `openapi.yaml`, guide et clients | Contrat actuel sans services ni administration historique | Mise à jour contractuelle coordonnée avant activation, codes 403/404/409/422/429 et schémas de secret explicites |

### 17.1 Catalogue d'opérations cible

Conserver les opérations Users/Roles/Permissions existantes et les écritures types/lieux, avec les nouvelles règles. Rôles : le PUT existant de rôle porte le statut ordinaire ; pas de doublon artificiel d'activation. Conserver les endpoints Auth et les dix opérations Demande, sous les seuls contrôles de référence et de sécurité décrits.

Ajouter :

- `GET /api/users/{id}/organisation-history` pour l'historique administratif ; `POST /api/users/{id}/temporary-password/reveal` pour la révélation spécifique.
- `GET /api/parametrage/directions` et `GET /api/parametrage/services` pour les listes administratives complètes ; `GET /api/directions/{id}`, `POST /api/directions`, `PUT /api/directions/{id}`, `PUT /api/directions/{id}/activate`, `PUT /api/directions/{id}/deactivate` ; mêmes opérations détail/création/modification/statut sous `/api/services`.
- `GET /api/parametrage/types-sortie`, `GET /api/parametrage/entites-geographiques` pour les listes complètes ; `PUT /api/types-sortie/{id}/activate` et `/deactivate` ; mêmes opérations de statut pour `/api/entites-geographiques/{id}`.
- `GET /api/parametrage/audit` et `GET /api/parametrage/audit/{id}` ; aucun endpoint d'édition du journal.

Tous ces nouveaux chemins sont administratifs et soumis à la section 3. Les listes opérationnelles existantes restent à leurs chemins actuels. Le rattachement se traite par l'opération de modification utilisateur existante avec le couple direction/service, l'historique et la version ; pas de second endpoint concurrent modifiant les mêmes champs sans garde.

Les schémas des réponses de création/reset et les listes d'attribution vides changent : prévoir adaptation explicite des clients, sans maintien d'un endpoint ancien moins protégé. Les exemples Angular, Flutter et Postman devront distinguer droits effectifs, rôle attribué inactif, références actives et anciennes valeurs affichées.

## 18. Scénarios d'utilisation

1. **Créer un agent** : l'administrateur choisit direction active et éventuel service compatible ; valide une seule commande ; compte, rôle demandeur et audit sont créés ensemble. Il récupère le temporaire par l'action dédiée puis le communique par un canal autorisé ; aucune notification automatique n'est ajoutée.
2. **Changer de direction** : un service ancien est présent. L'administrateur doit choisir un nouveau service compatible ou explicitement aucun ; la transaction écrit l'avant/après et la date. Les demandes historiques restent inchangées.
3. **Désactiver une direction** : les services conservent leurs statuts propres et les comptes leurs accès. La direction et ses services ne sont plus sélectionnables pour de nouveaux rattachements ; les liens anciens restent visibles.
4. **Suspendre un rôle ordinaire** : les titulaires gardent le rôle mais perdent immédiatement sa contribution aux permissions. Les droits provenant d'un autre rôle actif restent disponibles ; réactiver restitue la contribution sans réattribuer les comptes.
5. **Deux administrateurs se retirent simultanément** : les commandes sont sérialisées ; la première peut réussir si l'autre demeure, la seconde reçoit 409. Il reste un administrateur effectif après commit.
6. **Archiver un lieu utilisé** : le lieu devient inactif, sans altération des demandes existantes. Une tentative de modification de ses coordonnées retourne 409 ; une nouvelle entrée peut être créée explicitement.
7. **Brouillon utilisant une référence archivée entre-temps** : lecture possible ; édition du motif possible en conservant la valeur ; soumission refusée tant qu'une référence active n'est pas choisie.
8. **Reset répété après délai réseau** : la même clé retrouve le succès initial sans générer un second temporaire ni second audit de reset ; une révélation séparée vérifie de nouveau droits et génération.
9. **Administrateur rétrogradé avec un ancien token** : nouvelle demande administrative refusée, même si la session/token n'a pas expiré ; pas de résultat de commande sensible renvoyé avant autorisation actuelle.
10. **Correction d'une donnée officielle** : un service de mauvaise direction n'est pas déplacé en masse ; créer le service correct, transférer explicitement les comptes avec historique, désactiver l'ancien. Ne pas inventer la décision organisationnelle.

## 19. Critères d'acceptation vérifiables

Ces critères sont des preuves attendues, pas des tests exécutés.

| ID | Vérification attendue |
| --- | --- |
| AC01 | Sans token : 401 ; utilisateur ordinaire ayant toutes les permissions administratives mais sans rôle système : 403 sur toutes les opérations administratives. |
| AC02 | Administrateur actif et permissionné : accès autorisé ; permission requise absente dans un état de test contrôlé : 403, sans contournement global. |
| AC03 | Changement obligatoire : aucun accès Paramétrage/métier ; Auth autorisé selon section 3 ; changement réussi efface le drapeau et remplace les tokens. |
| AC04 | Demandeur : trois lectures opérationnelles actives autorisées, administration et archives refusées ; aucune fuite des champs administratifs ou secrets. |
| AC05 | Création compte : direction requise, service compatible facultatif, rôle demandeur automatique ; échec d'une étape annule tout. |
| AC06 | Service d'une autre direction : 422 ; référence inexistante : 404 ; aucune modification partielle. |
| AC07 | Nouveau rattachement inactif refusé ; rattachement ancien inchangé conservé lors d'un changement de nom/email. |
| AC08 | Désactivation de direction ne change ni statut des services ni statut/roles/tokens des comptes. La sélection tient compte du parent. |
| AC09 | Aucun DELETE direction/service ; aucune cascade sur demandes ou historiques. |
| AC10 | Changement de rattachement : un seul événement daté avec avant/après et motif ; ancien libellé conservé après renommage. |
| AC11 | Désactivation utilisateur révoque tous les tokens et conserve rôles/demandes ; réactivation ne crée pas de token et ne réactive pas ses rôles inactifs. |
| AC12 | Détail/liste/création/reset utilisateur sans secret ; `read_user` seul ne révèle jamais un temporaire. |
| AC13 | Révélation : permission spécifique, rôle, réauthentification, motif, version/génération ; `no-store` et audit sans secret ; refus si copie absente. |
| AC14 | Reset : aléatoire 12, hash et copie chiffrée, changement obligatoire et révocation ; pas de retour au secret fixe historique. |
| AC15 | Rôle inactif : contribution retirée immédiatement dans middleware, Gate, service et `/me`, y compris après cache chauffé ; autres contributions préservées. |
| AC16 | Rôles système non renommables/supprimables/désactivables ; codes applicatifs non renommables/supprimables ; champ système injecté rejeté. |
| AC17 | Retrait d'un socle système refusé en totalité ; listes vides permises pour objets ordinaires et utilisateur non protégé. |
| AC18 | Rôle avec utilisateurs ou permissions : suppression 409 ; permission avec rôle ou utilisateur direct : 409 ; pas de détachement silencieux. |
| AC19 | Dernier administrateur protégé sur chaque chemin direct et indirect et contre auto-retrait ; deux commandes concurrentes ne laissent pas zéro administrateur. |
| AC20 | Première utilisation fige contenu type/lieu ; remplacement ou suppression d'une copie ne lève pas le gel ; suppression utilisée 409. |
| AC21 | Archive d'une référence ne réécrit pas les demandes ; brouillon lisible mais soumission avec référence inactive 409 ; aucun changement automatique de statut métier. |
| AC22 | Bornes des coordonnées et précision vérifiées ; null conservé ; format décimal JSON compatible ; adresses homonymes permises. |
| AC23 | Mutation réussie et audit/historique atomiques ; audit indisponible : pas de mutation ni de révélation ; refus sensibles tracés hors rollback métier. |
| AC24 | Recherche de mots de passe/hash/copies/tokens dans audit, journaux HTTP/APM et registre de commandes ne trouve aucune valeur secrète de test. |
| AC25 | Même clé/intention : un seul effet ; autre intention : 409 ; ancienne version avec nouvelle clé : 409 ; acteur révoqué refusé avant tout rejeu. |
| AC26 | Course reset/changement/login/désactivation ne produit pas de token utilisable émis hors de l'ordre transactionnel attendu. |
| AC27 | Course suppression/archivage référence versus création/soumission Demande ne laisse aucune référence orpheline ni sélection invalide validée après désactivation. |
| AC28 | Pagination administrative : 20 par défaut, 100 accepté, 101/0/non entier refusés ; listes d'archives non accessibles par lectures opérationnelles. |
| AC29 | Installation et mise à niveau préservent les permissions métier existantes, les demandes et les secrets des comptes ; aucun TestDataSeeder en production. |
| AC30 | OpenAPI, guide, clients et routes réalisés correspondent au nouveau contrat ; aucun ancien chemin ne contourne les protections. |
| AC31 | Référentiel officiel et comptes rapprochés avant activation de la contrainte de direction ; historique initial marqué comme observation, sans dates fabriquées. |
| AC32 | Aucun écran/endpoint modifiant librement le serveur ou les statuts du workflow ; aucune fonctionnalité Traitement / Affectation introduite par Paramétrage. |

## 20. Stratégie de tests

Constat : la suite existante couvre déjà Auth, CRUD, conflits de suppression, IDs inexistants, certaines attributions, les référentiels, Demande, seeders et contrat OpenAPI. Les listes non vides et la consultation du temporaire reflètent notamment l'ancien contrat ; leur présence ne prouve pas les futures protections. Les tests examinés ne démontrent pas la concurrence du dernier administrateur ni le filtrage effectif des rôles inactifs.

Prévoir des tests HTTP pour tous les critères AC01–AC32 avec acteurs explicites : anonyme, utilisateur sans permission, demandeur, utilisateur ordinaire ayant une permission administrative, administrateur forcé de changer son mot de passe, administrateur actif, compte inactif et ancien token après perte de droits. Vérifier réponse **et** absence/présence des changements en base, pivots, audit, historique et tokens. Employer les factories et conventions PHPUnit du projet ; ne pas tester uniquement la présence d'un middleware ou le nom d'une route.

Tests ciblés prioritaires :

- Retrait réel depuis une attribution préexistante, et pas seulement ajout initial ; `[]` ; socle manquant ; rôle inactif déjà attribué versus nouvelle attribution.
- Cache de permissions préchauffé puis désactivation/réactivation ; fusion de deux rôles et ancienne permission directe ; résolution identique dans tous les points d'entrée.
- Pannes injectées entre compte, pivots, audit, historique et génération de jeton : aucune transaction partielle ni secret dans l'erreur.
- Modification concurrente de version ; double clic, délai réseau après commit, même clé/payload différent ; suppression puis recréation d'une cible ne doit pas réutiliser une ancienne identité.
- Relation service/direction, FK cible, organisation inactive préexistante et changements explicites ; ne pas confondre classe de service métier et service organisationnel.
- Gel et reprise des usages présents dans les JSON de modifications, suppression d'une copie puis nouvelle tentative d'édition, instantanés de libellés.
- Contrats JSON complets : absence des secrets ordinaires, droits réellement effectifs, coordonnées décimales, nullable et listes vides, codes HTTP et erreurs de champ ; validation OpenAPI et correspondance routes/opérations.

Pour la concurrence : suite dédiée isolée sur MySQL de même famille/configuration que l'exploitation, connexions/processus distincts et synchronisation déterministe. Couvrir les deux ordres de victoire et interblocages/reprises. SQLite en mémoire, actuellement prévu par `phpunit.xml`, convient à de nombreux tests fonctionnels mais **ne prouve pas** les verrous MySQL ou l'atomicité multi-connexion attendue.

Régression complète future : Auth, Users, Roles, Permissions, Demande, Direction, TypeSortie, EntiteGeographique, Seeders et contrat. Compléter par les services organisationnels, audit, rattachements et concurrence. L'exécution finale et le nombre de tests/assertions seront des résultats de l'implémentation ; ils ne sont pas inventés dans cette mission documentaire.

## 21. Migration des données existantes et précautions de déploiement

Cette section définit les précautions de la future réalisation ; aucune commande n'est exécutée ici et aucun schéma n'est modifié.

Procéder par ajouts, sans modifier les migrations déjà exécutées : services, champs temporaires compatibles, identités système, versions, statuts des référentiels, historique, audit, commande/garde et mécanismes d'instantanés. Les contraintes finales interviennent seulement après inventaire, correction et preuve de compatibilité. Aucun reset de base ni suppression de demandes.

Avant bascule :

1. Prévoir une sauvegarde vérifiée et un essai de restauration hors production. Inventorier comptes sans direction, incohérences, doublons/casse des codes, droits directs, rôles administrateurs, références historiques et données géographiques invalides.
2. Obtenir les données officielles I01 ; saisir/créer directions et services réels via un processus contrôlé et rapprocher **tous** les comptes existants, y compris inactifs et premier administrateur. Pas de direction fictive « par défaut » pour contourner une contrainte.
3. Conserver `direction_id` nullable sans nouvelle contrainte bloquante durant cette transition. Ne rendre la direction obligatoire et ajouter les FK restrictives/composites qu'après absence d'orphelins et de comptes non rapprochés. Si des données manquent, bloquer cette étape, pas effacer ou désactiver silencieusement les comptes.
4. Identifier les rôles système existants sans dupliquer leurs utilisateurs ni remplacer leurs rôles ; synchroniser les socles additivement sous garde. Préserver les permissions Demande du demandeur et ses trois lectures de référentiels. Ne pas synchroniser aveuglément `super_admin` avec tous les futurs droits métier.
5. Adapter le bootstrap administrateur : une installation neuve exige une direction officielle fournie par l'installateur avant création du compte ; une installation existante conserve password, statut et rôles supplémentaires. La préparation initiale contrôlée n'est pas une API publique sans authentification.
6. Marquer les références dont l'usage est déjà prouvé, y compris copies JSON ; capturer les états observés avec provenance. Les références existantes restent actives par défaut sauf décision explicite de l'administrateur, sans prétendre connaître un ancien statut absent du modèle.
7. Livrer de manière coordonnée les garde-fous, l'évaluateur de permissions et **tous** les écrivains concernés avant ouverture des écrans. Une version mixte dont un ancien writer ignore le verrou ne démontre pas la garantie : prévoir une fenêtre de maintenance des écritures concernées ou une bascule équivalente maîtrisée.
8. Vérifier hors production installation reproductible et mise à niveau avec données représentatives, puis les preuves fonctionnelles et concurrentes de la section 20. Préparer clients et documentation contractuelle avant retrait de l'ancien accès au temporaire.

Ne pas lancer `TestDataSeeder` pour synchroniser un rôle ; ne pas utiliser de données de test dans la base réelle. Les commandes de synchronisation existantes doivent être revues pour participer au protocole cible, rester additives et supporter un aperçu non mutateur. Aucun secret par défaut n'est ajouté au dépôt.

Retour arrière : ne pas supprimer les nouvelles tables d'histoire ou revenir à un code réexposant les secrets pour réparer une anomalie. Prévoir un retour applicatif compatible avec le schéma additif ou la fermeture temporaire des écritures concernées ; la restauration d'une sauvegarde après nouvelles écritures nécessite une décision d'exploitation et une prise en compte de ces écritures, pas une commande automatique destructive.

## 22. Hypothèses et validations institutionnelles réellement indispensables

| Référence | Validation indispensable | Ce qui est déjà décidé sans attendre |
| --- | --- | --- |
| I01 | Liste officielle des directions/services/codes et correspondance des comptes, dont l'administrateur initial | Modèle direction obligatoire, service facultatif cohérent, historique, pas de données fictives ; attente limitée au contenu et à la bascule des contraintes |
| I02 | Titulaires habilités `super_admin`, contrôle initial et responsable/procédure de récupération exceptionnelle | Accès exclusif, socles protégés, dernier administrateur, aucune porte dérobée ni attribution automatique à un nouveau responsable inventé |
| I03 | Conservation, archivage, rectification et accès réglementaire aux données personnelles de l'audit et des historiques | Append-only ordinaire, minimisation, absence de secrets et de purge automatique ; aucune durée légale supposée |

Ces informations ne bloquent pas la présente spécification ; elles conditionnent les points de mise en service correspondants. Les autres choix de V1 sont arrêtés dans le registre C01–C15 et ne sont pas renvoyés à une succession d'arbitrages utilisateur. Les questions propres au futur Traitement / Affectation restent dans ses documents et ne sont ni résolues fictivement ni développées ici.

La validation future du module exigera des résultats exécutés, un contrat OpenAPI à jour et une revue des voies de maintenance autant que de l'interface. À ce stade, le livrable est uniquement la conception des comportements et des preuves attendues.
