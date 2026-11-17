# Phase 1 / Step 1 — CI MySQL et tests d'architecture

> Périmètre : F-06 (CI sans MySQL), S-01 (isolation par convention), F-04/INV-09 (précision décimale). **Aucun correctif de F-01/F-02/F-03** ; aucune logique métier ni migration modifiée.

## 1. Ce qui a été ajouté

| Élément | Fichier | Rôle |
|---|---|---|
| Job CI `mysql-tests` | `.github/workflows/ci.yml` | MySQL 8.4 éphémère (service du job), `phpunit.mysql.xml`, suites Finance, Payments, Documents, Sales, Pos, Tenancy, Sandbox |
| Job CI `mysql-concurrency` | `.github/workflows/ci.yml` | `migrate` complet sur base vide + 4 scripts de concurrence (`tests/Concurrency/*`, vraies sessions parallèles) |
| Étape « Architecture guards » | `.github/workflows/ci.yml` (job `php-tests`) | Exécute `tests/Unit/Architecture` en premier (retour rapide) ; ces tests tournent aussi dans la suite complète |
| `ci-gate` | `.github/workflows/ci.yml` | `mysql-tests` et `mysql-concurrency` deviennent **bloquants** |
| Test d'isolation tenant | `tests/Unit/Architecture/TenantScopingTest.php` + `Support/TenantScopeScan.php` | Analyse AST : toute requête qui démarre sur un modèle/une table propre à une organisation doit nommer `organization_id` |
| Test de précision financière | `tests/Unit/Architecture/FinancialPrecisionTest.php` + `Support/FloatPrecisionScan.php` | Aucun `(float)`, `floatval`, `round/floor/ceil/fmod`, `array_sum`, ni arithmétique sur littéral flottant dans le code financier cœur |
| Listes d'exceptions documentées | `tests/Unit/Architecture/tenant-scope-exceptions.php`, `financial-precision-exceptions.php` | Chaque entrée porte une raison ; une entrée périmée fait échouer le test |
| Fixtures de contrôle du détecteur | `tests/Unit/Architecture/Fixtures/*` | Code synthétique prouvant que le détecteur signale les cas dangereux et ignore les cas sûrs |

## 2. Garanties de sécurité du job MySQL
- Le serveur MySQL est un **conteneur de service du job** : créé et détruit avec lui ; aucune base staging/production, aucun secret applicatif.
- Identifiants jetables dans le fichier de workflow (valeur de test, serveur inaccessible de l'extérieur).
- `phpunit.mysql.xml` reste inchangé : hôte `127.0.0.1` et base `commerce_erp_dev_test` **forcés** ; une étape de garde vérifie ces trois lignes avant toute exécution. Les scripts de concurrence refusent tout hôte non local et toute base ne finissant pas par `_test`/`_dev` (code 2).
- Les échecs échouent le job : le shell des jobs est `bash -eo pipefail` (défaut du workflow), donc `phpunit | tee` propage le code de sortie.
- Image `mysql:8.4` référencée par tag (les actions, elles, sont épinglées par SHA) : à épingler par condensat lors d'une revue de chaîne d'approvisionnement.

## 3. Comment fonctionne le test d'isolation (et pourquoi ce n'est pas un grep)
1. **Modèles propres à une organisation** : déduits des migrations (la table porte une colonne `organization_id`) ou du trait `ScopesToActiveOrganization` ; `User` et `Permission` sont explicitement plateforme.
2. **Racines de requête** uniquement : `Modèle::query()/where()/find()/…` et `DB::table('<table tenant>')` (y compris alias et `Modèle::environmentTable()`). Les relations depuis un parent déjà borné ne sont pas des racines.
3. **Signal d'isolation accepté** : `organization_id` dans la chaîne d'appels (colonne littérale, clé de tableau, colonne qualifiée, condition de jointure) ; constructeur stocké dans une variable puis borné plus loin (y compris via ternaire) ; tableau d'identité construit avant (`where($identity)`) ; ligne singleton dont la clé primaire **est** l'organisation (séquences de numérotation).
4. **Exceptions** : clé `<fichier>::<Classe::méthode>::<Modèle>` avec raison. Trois sortes : **REVIEWED** (sûr avec preuve écrite), **NEEDS STRENGTHENING** (non exploitable aujourd'hui mais à filtrer par `organization_id`) et **UNREVIEWED BASELINE** (à investiguer). Deux **cliquets** (`UNREVIEWED_CEILING = 0`, `NEEDS_STRENGTHENING_CEILING = 14`) interdisent aux constats ouverts de grossir ; la revue finale est dans `TENANT_SCOPE_BASELINE_REVIEW.md` (65 constats d'origine : 51 sûrs, 14 à renforcer, 0 à investiguer, 0 vulnérabilité).
5. Le test échoue aussi si une exception ne correspond plus à rien (la liste ne peut pas pourrir).

**Ajouter une requête légitime sans `organization_id`** : d'abord ajouter le filtre ; sinon ajouter une entrée `REVIEWED` expliquant pourquoi aucune autre organisation n'est atteignable. Ne jamais augmenter `UNREVIEWED_CEILING` ni `NEEDS_STRENGTHENING_CEILING`.

## 4. Exécution locale
```
# tests d'architecture (aucune base)
php vendor/bin/phpunit -c phpunit.xml tests/Unit/Architecture
# MySQL : voir LOCAL_MYSQL_TESTING.md ; base jetable ; jamais le serveur de déploiement
php -d memory_limit=2G vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Finance tests/Feature/Payments tests/Feature/Documents tests/Feature/Sales tests/Feature/Pos tests/Feature/Tenancy tests/Feature/Sandbox
php tests/Concurrency/number_sequence_race.php --sessions=4 --database=commerce_erp_race_test
```
