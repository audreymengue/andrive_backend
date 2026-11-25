# ANINF — Backend API

## Installation d'un environnement de développement

Prérequis : Git, Composer, PHP 8.3 avec `pdo_mysql` et `pdo_sqlite`, et MySQL.
`pdo_sqlite` sert à la suite PHPUnit, qui utilise une base isolée en mémoire.
Les dépendances PHP sont fixées par `composer.lock` ; aucune installation
frontend n'est nécessaire pour tester cette API.

1. Cloner le dépôt en remplaçant le marqueur par son URL réelle :

   ```powershell
   git clone <URL_DU_REPOSITORY> aninf-api-test
   cd aninf-api-test
   ```

2. Installer les dépendances, y compris celles de développement :

   ```powershell
   composer install
   ```

3. Copier le fichier d'environnement :

   ```powershell
   Copy-Item .env.example .env
   ```

   Sur Linux/macOS, utiliser `cp .env.example .env`.

4. Renseigner dans `.env` une valeur privée de **8 à 12 caractères** pour
   `ANINF_INITIAL_ADMIN_PASSWORD`. Ne pas laisser cette variable vide à la
   première initialisation. Elle crée le compte `admin@aninf.ga` avec un mot
   de passe hashé et `must_change_password=true`. `.env.example` contient
   seulement `ANINF_INITIAL_ADMIN_PASSWORD=` et `.env` est ignoré par Git.

5. Créer la base de test dans MySQL et configurer les accès dans `.env` :

   ```sql
   CREATE DATABASE aninf_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=aninf_test
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   Adapter l'utilisateur et le mot de passe MySQL à son installation. Ne pas
   définir `DB_URL` vers une autre base. Si la configuration a été mise en cache,
   exécuter `php artisan config:clear` après toute modification de `.env`.

6. Générer la clé de chiffrement locale :

   ```powershell
   php artisan key:generate
   ```

7. Initialiser l'environnement avec la commande standard :

   ```powershell
   php artisan migrate --seed
   ```

   Sur `aninf_test`, `DatabaseSeeder` appelle `TestDataSeeder`, qui exécute
   `PermissionSeeder` → `RoleSeeder` → `AdminUserSeeder`, puis les directions,
   comptes de démonstration, références géographiques et demandes de test.
   Il crée 67 permissions, 5 rôles, 9 comptes de test, 3 directions, le seul
   type `TEST_SORTIE`, 7 entités géographiques, 12 demandes, leurs historiques,
   une modification et un profil chauffeur QA. Aucune valeur métier de type de
   sortie n'est préinstallée.

   `OfficialOrganisationSeeder` charge ensuite les directions et services officiels.
   Pour le formulaire de demande, `GET /api/directions` fournit aussi la liste
   `rattachements` de chaque direction. La Direction générale propose Conseillers,
   Chargés d’Étude, Coordonnateur, Cabinet et Autre. Si un choix est fait, envoyer
   son `id` dans `rattachement_direction_id`. Une demande accepte aussi la direction
   seule. Les services des autres directions continuent d’utiliser `service_id`.

8. Vérifier le backend avec les profils de test du dépôt.

   ```powershell
   php vendor/bin/phpunit
   ```

   Cette commande lance les tests généraux sur SQLite en mémoire. Les commandes
   suivantes complètent la vérification sur MySQL. Les deux premières utilisent
   la base locale `aninf_test` et ses migrations à jour. La suite réseau crée sa
   propre instance MySQL locale et y rejoue aussi la suite Paramétrage.

   ```powershell
   php vendor/bin/phpunit -c phpunit.parametrage.xml
   php vendor/bin/phpunit -c phpunit.parametrage.xml tests/Feature/TreatmentCourseApiTest.php tests/Feature/TransportApiTest.php
   php vendor/bin/phpunit -c phpunit.network.xml
   ```

9. Lancer le serveur de développement :

   ```powershell
   php artisan serve
   ```

   Dans Postman, utiliser l'URL affichée par le serveur comme `baseUrl`.
   Importer `openapi.yaml`, appeler `POST /api/login`, puis utiliser le token
   réellement retourné comme Bearer Token. Les tokens d'exemple du contrat
   OpenAPI ne sont pas utilisables pour s'authentifier.

## Comptes et scénarios Postman

| Rôle | E-mail | Mot de passe local |
| --- | --- | --- |
| Administrateur initial | `admin@aninf.ga` | Valeur privée de `ANINF_INITIAL_ADMIN_PASSWORD` |
| Super administrateur de test | `admin-test@aninf.ga` | `TestPass1!` |
| Demandeur 1 | `demandeur1@aninf.ga` | `TestPass2!` |
| Demandeur 2 | `demandeur2@aninf.ga` | `TestPass3!` |
| Demandeur 3 | `demandeur3@aninf.ga` | `TestPass4!` |
| Compte inactif | `inactif-test@aninf.ga` | `TestPass5!` |
| Opérateur DAG QA | `qa.dag@example.test` | `ANINF-QA26` |
| Chauffeur QA | `qa.chauffeur@example.test` | `ANINF-QA26` |
| Gestionnaire Transport QA | `qa.transport@example.test` | `ANINF-QA26` |

Ces identifiants de démonstration sont réservés à `aninf_test` et ne doivent
jamais être réutilisés en production. Les huit comptes QA actifs ne nécessitent
pas de changement initial de mot de passe ; le compte inactif permet de tester
le refus de connexion. Les comptes DAG, chauffeur et gestion Transport ont
uniquement les permissions de leurs parcours de test ; le compte chauffeur est
lié à un profil chauffeur actif. Le rôle `super_admin` reste réservé aux tests
d’administration. Les demandeurs peuvent consulter et gérer leurs demandes.

Pour recréer ou synchroniser uniquement les trois rôles et comptes QA sur une
base de test déjà initialisée, vérifier d’abord la base puis lancer :

```powershell
php artisan config:show database.connections.mysql.database
php artisan db:seed --class=QaRoleAccountsSeeder --no-interaction
```

La première commande doit afficher `aninf_test`. Le seeder dédié refuse toute
autre base et ne supprime pas les données existantes. Il rattache les comptes à
la DSI si elle est active, sinon à la première direction active existante ; il
ne crée aucune direction. Sur une installation neuve, `php artisan migrate
--seed` crée déjà ces comptes.

L'administrateur initial doit définir son premier mot de passe via
`PUT /api/me/password` avant d'accéder aux endpoints métier. Le temporaire
généré par un reset API est hashé pour l'authentification et stocké chiffré pour
la consultation administrative autorisée ; il est effacé après changement.

Les demandes de démonstration couvrent les sept statuts du module, les
destinations ordonnées, les objets facultatifs, les historiques et une
modification en cours. Les références sont au format `JJ-MM-AA-XXXX` ; obtenir
les identifiants réellement présents via l'API avant de construire un payload.

## Notifications push pour Flutter et Angular

L’activation du push est reportée pour cette livraison. Conserver `PUSH_ENABLED=false`.
Les clients peuvent intégrer dès maintenant la boîte personnelle via
`GET /api/notifications` et son compteur via `GET /api/notifications/unread-count`.
La procédure ci-dessous servira à la recette sur appareil lorsque Firebase sera configuré.

Le push reprend les événements de la boîte personnelle. Le demandeur reçoit
les avis de validation de sa demande, de départ et de fin de course. Le
chauffeur reçoit l’avis d’affectation. Le contenu visible sur écran verrouillé
est générique. `data.notification_id` permet au client de retrouver la
notification dans `GET /api/notifications` après authentification. La réception
du push laisse la notification non lue.

1. Le client demande la permission d’affichage, obtient le jeton FCM du SDK
   et crée un UUID aléatoire conservé pour cette installation.
2. Après connexion, puis à chaque renouvellement du jeton Firebase ou de la
   session Sanctum, il envoie `PUT /api/me/push-devices/{installation_id}`
   avec le Bearer Sanctum et un corps JSON contenant `plateforme`
   (`android`, `ios` ou `web`) et `token` (jeton FCM).
3. Pour retirer le consentement, il appelle
   `DELETE /api/me/push-devices/{installation_id}`. La révocation de la
   session par le backend retire aussi son inscription. Après un changement
   de compte, ouvrir les détails uniquement pour la session en cours.

Pour activer les envois, configurer `PUSH_ENABLED=true` et
`FIREBASE_CREDENTIALS` avec le chemin absolu d’un fichier de compte de service
privé autorisé à envoyer des messages via FCM HTTP v1. Le fichier de clé reste
sur le serveur hors de Git et du répertoire public. Appliquer les migrations
puis démarrer un worker supervisé avec `php artisan queue:work database`.
Après modification de la configuration mise en cache, la régénérer et
redémarrer les workers. Le push est désactivé par défaut.

Sur iOS, configurer APNs dans Firebase. Sur le Web, prévoir HTTPS, les clés
VAPID et le service worker de messagerie. Les clients doivent traiter les
notifications au premier plan et à l’ouverture depuis l’arrière-plan selon
leur SDK. [Guide Flutter](https://firebase.google.com/docs/cloud-messaging/flutter/get-started)
et [guide Web](https://firebase.google.com/docs/cloud-messaging/web/get-started).

## Charger la liste officielle du pool et du parc

Sur un poste autorisé, placer le fichier de données institutionnelles
`parc-officiel.json` dans `storage/app/private/`. Ce fichier contient les
identités et les téléphones professionnels. Il se transmet par le canal
interne de l’agence et est exclu de Git. Sur un nouveau poste, le seeding
standard charge le parc officiel lorsque ce fichier est présent.

Sur une base de développement déjà initialisée, vérifier que la première
commande affiche `aninf_test`, puis appliquer les migrations et le seeder dédié.

```powershell
php artisan config:show database.connections.mysql.database
php artisan migrate --database=mysql
php artisan db:seed --class=OfficialFleetSeeder --database=mysql
```

Le fichier comporte deux listes. Chaque chauffeur est identifié par une
`reference` technique stable, indépendante de son compte. Les autres champs
sont `nom_complet`, `telephone` et `mention_pool`. Conserver la même référence
si l’orthographe du nom est corrigée. Chaque véhicule est identifié par son
`immatriculation` et possède `marque`, `modele`, `nombre_places` et `statut`.
Le nombre de places du document inclut le conducteur. L’API expose la capacité
passagers dans `places_utiles` (5 places au total donnent 4 places utiles).
Les véhicules signalés hors service sont importés avec le statut `INACTIF`.

Les profils chauffeurs du document sont consultables dans
`GET /api/parametrage/chauffeurs`. Une personne habilitée peut ensuite lier
chaque profil au compte vérifié de son titulaire pour permettre l’affectation
aux courses. Les mentions du pool sont descriptives. Les utilisateurs et leurs
habilitations sont gérés séparément.

## Réexécuter l'initialisation

`php artisan migrate --seed` est la commande standard, également sur une base
déjà initialisée. Le jeu de démonstration est mis à jour sans dupliquer ses
références, y compris si le seeder est relancé un autre jour. Le mot de passe
de l'administrateur initial existant n'est pas remplacé par le seeder.

Pour recréer complètement **la base de test** :

```powershell
php artisan config:show database.connections.mysql.database
php artisan migrate:fresh --seed
php artisan migrate:status
```

La configuration doit indiquer `aninf_test`. `migrate:fresh` supprime les tables
et leurs données avant reconstruction : ne jamais l'exécuter sur `aninf` ou
une base contenant des données à conserver. La protection de `TestDataSeeder`
refuse une base active différente de `aninf_test`, mais intervient au moment
du seeding et ne protège pas contre une commande `migrate:fresh` mal ciblée.

Pour appliquer uniquement la mise à jour du référentiel officiel sur une base
déjà initialisée, vérifier la base configurée puis exécuter les migrations et le
seeder institutionnel.

```powershell
php artisan config:show database.connections.mysql.database
php artisan migrate
php artisan db:seed --class=OfficialOrganisationSeeder
```

Sur une autre base standard, `DatabaseSeeder` charge les permissions, les rôles,
le référentiel institutionnel et l’administrateur initial.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
