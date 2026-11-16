# Registre des arbitrages — Module Paramétrage

Date : 27 octobre 2026. Statut : conception documentaire, non implémentée.

Document détaillé associé : [SPECIFICATION_MODULE_PARAMETRAGE.md](SPECIFICATION_MODULE_PARAMETRAGE.md).
Source d'observation : [AUDIT_MODULE_PARAMETRAGE.md](AUDIT_MODULE_PARAMETRAGE.md), confronté aux routes, services, validations, modèles, migrations, seeders et tests actuels. Une spécification du futur module Traitement / Affectation n'est pas une preuve d'implémentation.

## 1. Décisions métier arrêtées

| ID | Décision conservée |
| --- | --- |
| M01 | Un seul module Paramétrage : utilisateurs, RBAC, directions, services, types de sortie, entités géographiques et seuls paramètres généraux indispensables. Administration V1 exclusivement par `super_admin`, contrôlée côté serveur. |
| M02 | Chaque service appartient obligatoirement à une direction. Chaque utilisateur appartient à une direction ; son service est facultatif et appartient à cette même direction. |
| M03 | Directions et services : création, lecture, modification, activation et désactivation, sans suppression définitive ordinaire. Une direction désactivée ne modifie pas le statut propre de ses services. Aucun nouveau rattachement à une structure inactive. |
| M04 | Les changements de rattachement sont historisés avec dates et anciennes valeurs. Aucune suppression des anciennes demandes ou de leur histoire. |
| M05 | Un compte créé reçoit automatiquement `demandeur`. Plusieurs rôles restent possibles. Désactiver révoque les jetons mais conserve les attributions et les demandes ; réactiver ne reconstruit pas les rôles. |
| M06 | Réinitialisation par mot de passe temporaire avec changement obligatoire. Consulter un utilisateur ne permet pas de lire ce secret : opération et autorisation spécifiques. Aucun secret dans l'audit. |
| M07 | Un rôle ordinaire inactif cesse immédiatement de contribuer aux permissions de tous ses titulaires ; aucune nouvelle attribution ; réactivation possible. |
| M08 | Protection renforcée des rôles système et des codes de permission employés par le code. Créer une permission ne crée pas une fonctionnalité. |
| M09 | Protection du dernier administrateur effectif, y compris changements indirects et concurrence. Contrôle des attributions et retraits de rôles et permissions. |
| M10 | Administration des référentiels sans perte d'histoire ; audit des opérations sensibles non modifiable depuis l'interface ; résistance aux doubles soumissions touchant les invariants. |

## 2. Choix autonomes de conception

Ces choix sont retenus pour la V1 ; ce ne sont pas des questions ouvertes ni des fonctionnalités déjà disponibles.

| ID | Choix et justification |
| --- | --- |
| C01 | Réutiliser les chemins administratifs existants en durcissant leur accès ; ajouter seulement les opérations manquantes. Les trois GET de référentiels nécessaires aux demandes restent des lectures opérationnelles permissionnées, sans accès à l'administration ni aux archives. Évite de casser le formulaire et de dupliquer toute l'API. |
| C02 | Autorisation administrative = compte actif, changement de mot de passe accompli, rôle système `super_admin` actif et permission de l'opération. Aucun privilège universel implicite sur les futurs workflows métier. Les droits issus des rôles inactifs sont filtrés partout, y compris dans les réponses de droits effectifs. |
| C03 | `super_admin` et `demandeur` sont des rôles système à identité stable, non renommables, non supprimables et maintenus actifs. Leur socle de permissions est protégé. Le socle `demandeur` conserve les huit droits actuels ; celui de `super_admin` couvre l'administration, pas automatiquement les futurs droits métier. Les attributions métier existantes ne sont pas retirées silencieusement. |
| C04 | Les rôles ordinaires peuvent avoir zéro permission. Un utilisateur peut recevoir un ensemble vide de rôles, sauf atteinte au dernier administrateur. Aucune attribution directe de permission aux utilisateurs en V1 ; les anciennes attributions directes sont inventoriées, conservées et prises en compte explicitement. |
| C05 | Codes de direction, service et type de sortie stables. Code service unique dans sa direction ; parent d'un service immuable. Un déplacement organisationnel se représente par un nouveau service et des rattachements explicites, sans déplacement collectif caché. |
| C06 | Désactivation organisationnelle sans désactivation des comptes. Les rattachements inchangés à une structure inactive restent consultables et n'empêchent pas une correction de profil. Tout nouveau rattachement exige une structure sélectionnable. |
| C07 | Historique append-only des rattachements avec auteur, date serveur, motif et instantanés avant/après. Reprise initiale datée de son observation, sans inventer la date d'affectation ancienne. Les directions déclarées dans les demandes ne sont pas réécrites quand un utilisateur change de direction. |
| C08 | Types et lieux reçoivent un statut actif/inactif ; l'archivage signifie désactivation. Dès leur première utilisation, leur contenu descriptif est figé ; une correction passe par une nouvelle référence, sans réécriture des anciennes demandes. Suppression uniquement si jamais utilisés et sans dépendance ; sinon 409. |
| C09 | Les références inactives sont conservées sur les demandes existantes. La soumission d'un brouillon ou d'une proposition modifiée exige des références actives ; une demande déjà engagée n'est pas automatiquement annulée ou revalidée par un archivage. |
| C10 | Aucun paramètre général modifiable supplémentaire n'est justifié en V1. Pas d'éditeur de `.env`, de configuration Laravel, de statuts du workflow ni de règles de mot de passe. Les arbitrages futurs déjà consignés ne deviennent pas ici des paramètres administrables. |
| C11 | La création et le reset ne renvoient plus le secret. Une opération dédiée de révélation, permissionnée, avec réauthentification et motif, permet sa communication contrôlée ; jamais via GET utilisateur. Mot de passe temporaire aléatoire et contraintes actuelles conservés : pas de retour au secret fixe historique. |
| C12 | Un administrateur effectif est un compte actif portant le rôle système `super_admin` actif et son socle administratif utilisable. `must_change_password=true` n'annule pas cette capacité recouvrable par changement de mot de passe ; une structure inactive n'annule pas le compte. Aucun contournement web de secours. |
| C13 | Transactions, verrou commun de sécurité, versions attendues et clés d'idempotence protègent les écritures sensibles. Toutes les voies d'écriture, y compris authentification et commandes de maintenance concernées, doivent participer au protocole. Garantie à prouver sur le moteur de production, pas seulement SQLite. |
| C14 | Audit atomique des mutations réussies, liste blanche des valeurs conservées et audit distinct des refus sensibles. Révélation de secret auditée à chaque lecture. Aucune conservation du secret, même chiffré ou hashé, dans l'audit ou les réponses d'idempotence. |
| C15 | Migration additive et progressive, avec rapprochement officiel des utilisateurs avant de rendre leur direction obligatoire en base. Aucun seeder de démonstration en production, aucun écrasement global des rôles existants et aucune purge automatique des historiques en V1. |

## 3. Écarts et incompatibilités avec l'existant

| Fait vérifié | Conséquence pour la future réalisation |
| --- | --- |
| Les routes administratives sont protégées par permissions, pas par un rôle `super_admin` obligatoire. | Changement d'autorisation assumé ; les comptes non administrateurs actuellement permissionnés perdront cet accès administratif. Préserver seulement les lectures opérationnelles C01. |
| `UserService` n'attribue pas automatiquement `demandeur` ; `direction_id` est nullable sans FK ; aucun service organisationnel. | Nouvelle logique de création et transition de données nécessaires, sans réécrire les anciennes migrations. |
| `UserController::show` expose encore le temporaire sous `read_user`. | Retirer cette exposition et adapter les clients ; une simple modification du guide serait insuffisante. |
| Le statut des rôles n'interrompt pas actuellement leurs permissions héritées. Aucun garde du dernier administrateur n'est implémenté. | Évaluateur effectif commun et protocole transactionnel à développer et tester. |
| `RoleController` autorise renommage et changement de droits sans identité système protégée. | Introduire l'identité système et les socles ; ne pas déduire la sécurité du seul nom modifiable. |
| Les listes d'attribution exigent actuellement au moins un élément. | Le contrat cible accepte `[]` pour un retrait complet autorisé, sous garde de sécurité. |
| Types et lieux n'ont pas de statut ni d'instantané historique. Les ressources affichent des relations vivantes. | Ajouts nécessaires ; les anciens libellés déjà écrasés ne peuvent pas être reconstitués artificiellement. |
| `RoleSeeder` synchronise `super_admin` avec toutes les permissions ; `AdminUserSeeder` peut remplacer les rôles d'un administrateur existant. | Les synchronisations futures doivent respecter les socles et les attributions existantes, et utiliser les mêmes garde-fous que l'API. |
| Certaines spécifications futures proposent d'autres règles Demande ; elles ne sont pas toutes implémentées. | Ni réalisation du module Traitement / Affectation, ni modification furtive de ses arbitrages dans cette mission. |

## 4. Seules validations institutionnelles restantes

| ID | Information attendue de l'ANINF | Effet et mesure retenue en attendant |
| --- | --- | --- |
| I01 | Organigramme officiel : directions, codes, services et correspondance de chaque compte existant, y compris le premier administrateur. | Documents terminés sans liste fictive. Saisie/import contrôlé à prévoir ; bascule vers direction obligatoire bloquée tant que les comptes ne sont pas rapprochés. |
| I02 | Personnes habilitées à porter `super_admin`, responsable du contrôle initial et procédure institutionnelle de récupération si aucun administrateur ne peut matériellement se connecter. | Aucun nouveau titulaire inventé, aucune porte dérobée. Contrôle avant mise en service ; procédure d'exploitation exceptionnelle distincte de l'interface ordinaire. |
| I03 | Politique de conservation, accès, archivage et rectification applicable aux historiques et audits contenant des données personnelles. | Aucune durée réglementaire inventée. V1 sans purge automatique ; politique et capacité de stockage à faire approuver avant exploitation durable. |

## 5. État des vérifications

Lecture statique du code, des tests et des documents ; versions directes relevées dans Composer. Aucun test, scénario concurrent, accès à la base, seeder, migration ou déploiement exécuté pour cette conception. Les garanties et validations dynamiques sont les critères de réalisation du document associé, non des résultats acquis.
