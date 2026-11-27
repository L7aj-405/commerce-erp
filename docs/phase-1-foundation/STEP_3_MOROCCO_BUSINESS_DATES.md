# Phase 1 / Step 3 — F-02 : temps légal marocain, dates métier et numérotation fiscale

> Branche `fix/phase-1-step-3-business-dates`, basée sur `origin/staging` @ `d167960` (Step 1 + F-01 déjà présents, PR #23 et #26). PR à ouvrir vers **`staging` uniquement** (jamais `main`).
> Statut Git de contexte : F-01 avait été fusionné dans `main` par erreur (PR #24) ; la correction (PR #25, revert de la fusion) est fusionnée dans `main` (`5285c6c`) et F-01 est dans `staging` (PR #26). Voir §19.

## 1. Résumé exécutif
- **Défaut F-02 confirmé** : les dates par défaut des documents commerciaux et fiscaux (facture, BL, avoir, devis, commande issue d'un devis) et l'« année » des séquences fiscales d'avoir/retour/échange étaient dérivées de `now()` (UTC) ; la garde de rétrodatage comparait la date demandée à la date UTC. Autour de minuit, ou autour du 1er janvier, un document marocain pouvait recevoir la date et le **numéro d'année de la veille** (ex. facture `1/2025` au lieu de `1/2026`).
- **Correction** : toutes ces dates passent par `BusinessTime::today($organization)` (fuseau de l'organisation) ; une seule aide a été ajoutée, `BusinessTime::currentYear()`. Aucun fuseau global, aucune règle codée en dur `UTC+1`/`UTC+0`.
- **Constat majeur d'environnement** : la base de fuseaux horaires de PHP du poste (`2025.2`) est **périmée** : elle prédit encore UTC+1 après le 20 septembre 2026 et une transition de Ramadan en 2027, ce qui décalerait d'une heure toute date métier marocaine après le 20/09/2026. Une garde de test et une ligne de check-list de déploiement ont été ajoutées ; **la version de tzdata des conteneurs staging/production est inconnue et doit être vérifiée**.
- 12 tests de documents (8 échouaient avant le correctif) + 12 tests de temps légal. **Sur un runtime avec tzdata ≥ 2026c** (conteneur jetable PHP 8.4.27, tzdata 2026.5) : **code intégré (staging `d167960` + Step 3)** : SQLite 2333 tests / 17 963 assertions, **0 échec, 0 ignoré** ; MySQL 8.4 critique 1246 tests / 10 148 assertions, **0 échec, 0 ignoré**. Sur le poste Windows (tzdata 2025.2) la garde échoue volontairement : elle n'a été ni retirée, ni contournée, ni transformée en skip.
- Le runner CI GitHub avait **tzdata 2025.2** (l'extension `timezonedb` non épinglée écrasait le tzdata système) : corrigé par épinglage `timezonedb-2026.6` + étape bloquante `scripts/check-timezone-runtime.php`.
- **5 tests hérités** (Finance, Attention, WorkCenter, MCP) utilisaient des dates après le 20/09/2026 en supposant UTC+1 ; ils ont été déplacés dans la fenêtre UTC+1 (juin/juillet 2026) — voir §14.

## 2. Références légales officielles
Fournies par la demande (non vérifiées par moi sur le texte officiel, dont l'accès ne m'est pas disponible) :
- Décret n° 2.26.530 du 25 juin 2026, BO n° 7521 du 29 juin 2026 : <https://bdj.mmsp.gov.ma/Ar/Document/10664-D%C3%A9cret-n-2-26-530du-9-moharrem-1448-25-juin-2026.aspx>
- Annonce officielle du 18 septembre 2026 : <https://www.cg.gov.ma/en/node/13036>

Corroboration indépendante du **comportement des données** (pas du texte légal) : la base IANA a intégré le changement dans la version **2026c** (publiée le 8 juillet 2026) — [proposition tz « Morocco goes to plain UTC on 2026-09-20 »](https://lists.iana.org/hyperkitty/list/tz@iana.org/message/F5ZS6S346Q7KBI6OWYS4LMHXLZWVRWOH/), [annonces 2026](https://lists.iana.org/hyperkitty/list/tz-announce@iana.org/2026/7/), [avis Dell : les systèmes en 2026b affichent encore GMT+1](https://www.dell.com/support/kbdoc/en-us/000510668/avamar-africa-casablanca-time-zone-does-not-reflect-morocco-permanent-gmt-change). Le numéro de décret n'a été retrouvé que dans des sources secondaires.

## 3. Chronologie du temps légal (utilisée par les tests)
| Période | Décalage | Note |
|---|---|---|
| jusqu'au 2026-02-15 02:00Z | UTC+1 | régime 2018 |
| 2026-02-15 → 2026-03-22 02:00Z | UTC+0 | Ramadan 2026 |
| 2026-03-22 → 2026-09-20 01:00Z | UTC+1 | |
| **2026-09-20 02:00 local (01:00Z)** | retour de 60 min → **UTC+0** | décret 2.26.530 ; l'heure locale 01:00–02:00 se répète |
| depuis le 2026-09-20 | **UTC+0 (GMT)** | aucun changement d'horloge en 2027 (pas de Ramadan) |
Conséquence : au 1er janvier 2027 00:30, l'heure locale et l'UTC sont identiques ; l'ancienne hypothèse « UTC est encore le 31 décembre » est fausse. Les tests de frontière d'année utilisent donc l'instant **2025-12-31 23:30Z** (UTC+1 → 2026-01-01 00:30 à Casablanca), qui reproduit réellement le défaut.

## 4. Vérification de la base de fuseaux, par environnement
| Environnement | Version | Verdict |
|---|---|---|
| PHP du poste (Laragon) | PHP 8.4.12 NTS Windows, **tzdata 2025.2**, ICU 75.1, fuseau par défaut `UTC` | **PÉRIMÉ** (avant 2026c) |
| Node du poste (outils de build/SSR) | Node 24.13.0, ICU 77.1, tzdata **2025b** | périmé ; sans effet sur les utilisateurs (les navigateurs embarquent leur propre ICU) |
| MySQL | session épinglée à `+00:00` (`config/database.php`) ; **aucune fonction de fuseau SQL** dans le code applicatif (`CONVERT_TZ`, `CURRENT_DATE`, `NOW()`, `YEAR()` : 0 occurrence) | non concerné |
| Planificateur | `pos:cleanup-drafts` programmé en `Africa/Casablanca` (tzdata PHP) | dépend de la même base |
| Runners CI | **constaté** (run 44207235572) : PHP 8.4.27, tzdata **2025.2** (`timezonedb` non épinglé) → **PÉRIMÉ** ; corrigé : `timezonedb-2026.6` épinglé dans `.github/workflows/ci.yml` + étape bloquante | à confirmer au prochain run CI |
| Conteneurs staging / production | inconnu | **à vérifier** avec `php scripts/check-timezone-runtime.php` sur web, worker et planificateur : `DEPLOYMENT_CHECKLIST.md` §1 (mise à jour + retour arrière) |
| Image jetable `php:8.4-cli` officielle | PHP 8.4.27, tzdata **2026.5** ; script : 11 sondes OK, aucune transition en 2027 | **CONFORME** |
| Ubuntu 24.04 + PPA Ondřej (émule le runner) | `pecl install timezonedb-2026.6` → `timezone_version_get()` = **2026.6**, offsets 2026-11-17 / 2027-01-01 / 2027-03-15 = 0 | **CONFORME** après épinglage |
Sur le poste Windows, charger la DLL PECL a été **refusé** par l'environnement : non contourné ; la vérification locale se fait dans un conteneur Docker jetable, sans toucher au PHP du poste.

Sondes (instants UTC explicites ; **observé** sur tzdata 2025.2 vs **attendu** légalement) :
| Instant UTC | Attendu | Observé local | Écart |
|---|---|---|---|
| 2026-07-15 12:00 | +01 | +01 | — |
| 2026-02-20 12:00 (Ramadan) | +00 | +00 | — |
| 2026-03-21 / 03-22 12:00 | +00 / +01 | +00 / +01 | — |
| 2026-09-19 12:00 | +01 | +01 | — |
| 2026-09-20 01:30 | +00 | **+01** | ✗ |
| 2026-09-20 12:00 | +00 | **+01** | ✗ |
| 2026-11-17 12:00 | +00 | **+01** | ✗ |
| 2026-12-31 23:30 | local 2026-12-31 23:30 | **2027-01-01 00:30** | ✗ |
| 2027-01-01 00:30 | local 2027-01-01 00:30 | **01:30** | ✗ |
| 2027-02-10 12:00 (Ramadan 2027) | +00 | +00 | (hasard) |
| 2027-03-15 / 07-15 | +00 | **+01** | ✗ ; transitions 2027 fantômes `2027-02-07` et `2027-03-14` |
Commande reproductible : `php -r '$t=new DateTimeZone("Africa/Casablanca"); foreach(["2026-11-17 12:00","2027-01-01 00:30"] as $u){$d=new DateTime($u." UTC"); echo timezone_version_get()," ",$u,"Z offset=",$t->getOffset($d),PHP_EOL;}'` (doit afficher `0`).

## 5. Exemples avant / après (instant gelé 2025-12-31 23:30:00 UTC, organisation `Africa/Casablanca`)
| Document | Avant (code d'origine ; « mesuré » = observé par le test, « déduit » = d'après le code) | Après |
|---|---|---|
| Facture : date par défaut | `2025-12-31` | `2026-01-01` |
| Facture : numéro | `1/2025` (mesuré) | `1/2026` |
| Facture : `issued_at` (instant) | 2025-12-31 23:30:00 UTC | inchangé (UTC) |
| Bon de livraison : date | `2025-12-31` | `2026-01-01` |
| Devis : date par défaut | `2025-12-31` | `2026-01-01` (numéro `DEV-1/2026`) |
| Commande issue d'un devis : `sale_date` | `2025-12-31` | `2026-01-01` |
| Avoir brouillon : date | `2025-12-31` (mesuré) | `2026-01-01` |
| Avoir émis : date et numéro | `2025-12-31` / `AV-…/2025` (**déduit du code** : le test s'arrête à la première assertion en échec) | `2026-01-01` / `AV-00001/2026` |
| Retour : numéro | `RET-…/2025` (année UTC, **déduit du code**) | `RET-00001/2026` |
Au même instant, une organisation `America/New_York` ou **non configurée (UTC)** reste au `2025-12-31` / `1/2025` : le fuseau de Casablanca n'est jamais imposé aux autres organisations.

## 6. Matrice d'audit des chemins de dates
| # | Opération | Point d'entrée | Source de date d'origine | Date attendue | Année de séquence | Permission / garde de période | Gravité | Correction | Test |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Facture : création | `CreateFullInvoiceFromSalesOrderAction` | `now()` UTC | date métier org. | émission : année de `invoice_date` (`IssueInvoiceAction`) | `invoices.create` ; autre date ⇒ `invoices.backdate` ; **aucune garde de période** (F-03) | Moyenne (fiscal) | `BusinessTime::today($order->organization)` | `test_invoice_defaults_to…`, `…backdated…`, `…future_dated…` |
| 2 | Garde « date = aujourd'hui » | `AuthorizesDocumentAction::authorizeBusinessDate` (facture, BL, mises à jour de brouillons) | `now()` UTC | date métier org. | — | — | Moyenne (403 à tort / date UTC acceptée) | `BusinessTime::today($organization)` | `test_the_default_date_never_asks_for_the_backdating_permission` |
| 3 | Bon de livraison : création | `CreateFullDeliveryNoteFromSalesOrderAction` | `now()` UTC | date métier org. | séquence **sans année** | `delivery_notes.create/backdate` | Basse-moyenne | idem | `test_delivery_note_defaults…` |
| 4 | Avoir (brouillon + émission) | `CreateCustomerReturnAction`, `ReceiveCustomerReturnAction` | `now()` UTC | date métier org. | année de `credit_note_date` | pas de choix de date ; garde de période **absente** (F-03) | **Moyenne-haute** (numérotation fiscale) | `BusinessTime::today($organization)` | `test_credit_note_and_return…` |
| 5 | Retour / échange : numéro | `CreateCustomerReturnAction`, `CreateCustomerExchangeAction` | `now()->year` (année UTC) | année métier | année de création | — | Moyenne | `BusinessTime::currentYear()` | idem + course |
| 6 | Devis : création/duplication/révision | `CreateQuotationAction`, `Duplicate…`, `StartQuotationRevisionAction`, `McpQuotationDraftService`, formulaire `Quotations/Create.tsx` | `now()` UTC / `toISOString()` navigateur | date métier org. | émission : année de `quotation_date` | `quotations.create` | Moyenne | `BusinessTime::today` ; front : `useBusinessToday` + `addDaysToDate` | `test_quotation_defaults…` |
| 7 | Commande issue d'un devis | `ConvertQuotationToSalesOrderAction` | `now()` UTC | date métier org. | séquence sans année | — | Moyenne | idem | `test_converting_a_quotation…` |
| 8 | Commande de vente (MCP) | `McpSalesOrderService` (en-tête proposé) | `now()` UTC | date métier org. | — | — | Basse | idem | couvert par la même aide |
| 9 | Tableau de bord | `PlatformController` (« ventes du jour », mois par défaut) | `today()` / `now()` UTC | date/mois métier org. | — | — | Basse | `BusinessTime` | indirect |
| 10 | Aperçu des numéros | `DocumentProfileController`, `QuotationSettingsController` | `now()->year` | année métier | affichage | — | Basse | `BusinessTime::currentYear` | — |
| 11 | Défauts de formulaires financiers | `RecurringObligations.tsx`, `SupplierInstallments.tsx`, `FinancialAccounts/Index.tsx` | `toISOString()` navigateur | date métier org. | — | — | Basse | `useBusinessToday` | `tsc` |
| — | **Déjà corrects (non modifiés)** | paiements, remboursements, POS, séquences finance (dates explicites), échéances de facture (`date de facture + délai`) | `BusinessTime` / dates explicites | — | — | — | — | — | `BusinessDateTimezoneTest` (existant) |
| — | **Audités, laissés en l'état (hors périmètre)** | défauts de filtres de rapports de commissions (`CommissionLedgerController`, `CommissionReportFilters`, `CommissionRules.tsx`), nom de fichier d'export CSV | `now()` | — | — | — | Très basse | à traiter avec les rapports | — |

## 7. Causes racines
1. Défaut `now()->toDateString()` / `now()->year` : le fuseau de l'application est `UTC` (volontairement) ; il ne doit servir qu'aux **instants**.
2. La garde de rétrodatage comparait la date demandée à la date UTC, alors que le formulaire envoie la date métier (`business_today`) : refus ou acceptation erronés autour de minuit.
3. Dépendance silencieuse à la **version de tzdata** de l'environnement d'exécution, non surveillée.

## 8. Décisions d'implémentation de `BusinessTime`
- **Une seule abstraction** : `BusinessTime` (existante). Ajout unique : `currentYear(Organization): int`.
- Le fuseau reste celui de l'organisation (`settings.timezone`, `UTC` tant que non configuré) : aucune organisation n'est forcée sur le Maroc.
- Les instants (`issued_at`, `received_at`, audit) restent en UTC (`now()` conservé pour eux).
- **Aucune règle de décalage n'est codée dans l'application** : les règles légales viennent de la base de fuseaux. Pas de contournement applicatif : la correction du décalage horaire passe par la base à jour (garde de test + check-list), conformément à la consigne.
- Heure locale ambiguë du 20/09/2026 (01:00–02:00 répétée) : `BusinessTime::localToUtc` renvoie l'un des deux instants réels (test).

## 9. Numérotation fiscale
| Document | Séquence | Année d'allocation | Source de l'année |
|---|---|---|---|
| Facture | `N/AAAA`, verrouillée | année de **`invoice_date`** | date du document (peut être explicitement rétrodatée/postdatée) |
| Avoir | `AV-NNNNN/AAAA` | année de **`credit_note_date`** | date du jour métier à l'émission (désormais org.) |
| Devis | `DEV-N/AAAA` | année de **`quotation_date`** | date de **création** du devis (pas de son émission) |
| Retour / Échange | `RET-` / `EXC-` | année **métier courante** | création (désormais org.) |
| BL, commande, paiement, remboursement | séquence **sans année** | — | — |
Propriétés vérifiées : unicité (clés), isolation par organisation et par type, allocation atomique (verrou de ligne). **Course MySQL réelle** : `tests/Concurrency/number_sequence_race.php` (4 sessions parallèles, tous les générateurs, premier usage et ligne existante) : **PASSED, 0 échec, 0 interblocage** (facture `1..4/2026`, avoir, devis, retour, échange, BL). Aucun renumérotage ni trou introduit : seule la **date** qui détermine l'année change, pas l'algorithme. **Frontière d'année** : `tests/Concurrency/fiscal_year_boundary_race.php` course les générateurs (facture, avoir, devis, retour) en parallèle autour de 2025-12-31 23:30Z / 2026-01-01 00:30Z, pour trois fuseaux et des sessions des deux côtés de minuit UTC (20 contrôles PASS, 0 interblocage). L'émission HTTP complète de deux factures simultanées n'a pas de script dédié : l'année est une fonction déterministe de la date, couverte en test fonctionnel.

## 10. Rétrodatage et postdatage
Comportement **préservé** : une date explicite autorisée n'est jamais remplacée par « aujourd'hui » ; elle fixe aussi l'année de séquence (facture). Permissions inchangées : `invoices.backdate`, `delivery_notes.backdate`, `payments.backdate` (« autre date que le jour », donc aussi futures). Tests : facture rétrodatée au 2025-06-15 ⇒ `1/2025` ; postdatée au 2026-03-01 ⇒ `1/2026` ; `issued_at` reste l'instant réel. Pour la position client, un document postdaté reste dû (F-01, Step 2, hors de cette branche). **Décision comptable à prendre** : une facture postdatée doit-elle être refusée au-delà d'un horizon, ou comptée dans l'année de sa date ? (comportement actuel conservé).

## 11. Périodes financières (dépendance F-03)
`FinanceClosedPeriodGuard` **n'est toujours pas appliqué** à l'émission de facture ni à la création d'avoir (constat F-03 inchangé). La correction de F-02 ne modifie pas cela. Dépendance : la garde devra tester la **`invoice_date` locale** (maintenant correcte) et la `credit_note_date`. À traiter au Step 4, y compris le choix de politique (réouverture, refus). Aucune garde n'a été ajoutée ici.

## 12. Données de pré-production
Aucune migration, aucun changement de schéma, aucun renumérotage. Seuls pourraient être concernés des documents émis dans la fenêtre 00:00–01:00 locale en régime UTC+1 avec date **par défaut** (la différence n'existe qu'au 1er janvier 2026 pour la numérotation annuelle, ou la veille d'un jour quelconque pour la simple date) — très improbable avant le 2026-01-01 vu l'historique du produit. Détection **en lecture seule** à faire sur staging (non exécutée : pas d'accès) : comparer `invoices.issued_at` (UTC) converti en heure locale à `invoices.invoice_date` pour les factures où l'écart est exactement un jour ; tout cas trouvé relève d'un plan de nettoyage **séparé, soumis à approbation**. Aucune base persistante n'a été modifiée ni réinitialisée ; les seules bases utilisées sont jetables (SQLite en mémoire, instance MySQL locale éphémère dont le dossier de données est temporaire).

## 13. Fichiers modifiés (33 : 32 de code/tests/CI/déploiement + ce document)
Code : `app/Support/BusinessTime.php` ; `app/Actions/Documents/Concerns/AuthorizesDocumentAction.php`, `…/CreateFullInvoiceFromSalesOrderAction.php`, `…/CreateFullDeliveryNoteFromSalesOrderAction.php` ; `app/Actions/Returns/ReceiveCustomerReturnAction.php`, `…/CreateCustomerReturnAction.php` ; `app/Actions/Exchanges/CreateCustomerExchangeAction.php` ; `app/Actions/Quotations/{CreateQuotationAction,DuplicateQuotationAction,StartQuotationRevisionAction,ConvertQuotationToSalesOrderAction}.php` ; `app/Services/Mcp/Sales/{McpQuotationDraftService,McpSalesOrderService}.php` ; `app/Http/Controllers/{PlatformController,Documents/DocumentProfileController,Quotations/QuotationSettingsController}.php`.
Front : `resources/js/utils/businessDate.ts` (+ `addDaysToDate`), `pages/Quotations/Create.tsx`, `pages/Finance/RecurringObligations.tsx`, `pages/Finance/SupplierInstallments.tsx`, `pages/Payments/FinancialAccounts/Index.tsx`.
Docs : `DEPLOYMENT_CHECKLIST.md` (prérequis tzdata, mise à jour, retour arrière), ce document.
Infra/CI : `.github/workflows/ci.yml` (`timezonedb-2026.6` épinglé, étape bloquante de vérification dans `php-tests` et `mysql-tests`, course de numérotation en frontière d'année dans `mysql-concurrency`), `scripts/check-timezone-runtime.php` (nouveau).
Tests : `tests/Concurrency/fiscal_year_boundary_race.php` (nouveau) ; dates déplacées en UTC+1 dans `Finance/BusinessDateTimezoneTest.php`, `Finance/ReceivablesConsistencyTest.php`, `Attention/QuotationAttentionTest.php`, `WorkCenter/TeamWorkTest.php`, `Mcp/McpWorkCenterToolsTest.php` (+ formatage Pint des imports pour ce dernier, exigé par la porte CI).
**Ampleur** : le changement fonctionnel représente ≈ 44 lignes ajoutées / 17 supprimées côté PHP. Les 7 fichiers PHP historiques que j'ai dû toucher (`CreateCustomerExchangeAction`, `ConvertQuotationToSalesOrderAction`, `CreateCustomerReturnAction`, `ReceiveCustomerReturnAction`, `DocumentProfileController`, `McpQuotationDraftService`, `McpSalesOrderService`) portaient de la dette de style ; la porte CI « Pint sur fichiers modifiés » impose de les formater entièrement, d'où ≈ 315 lignes de **formatage pur** supplémentaires (Pint appliqué à cette liste exacte de fichiers, jamais à un dossier).
Aucun changement de montant, de permission, de requête de sécurité ni de logique de période.

## 14. Tests ajoutés
- `tests/Feature/Documents/BusinessDatesFiscalNumberingTest.php` (12) : facture (date, année, instant UTC), garde de rétrodatage sans permission, rétrodatage et postdatage explicites, trois organisations au même instant (Casablanca / New York / UTC) avec séquences indépendantes, montants inchangés, BL, devis (date, validité, numéro), conversion en commande, date explicite de commande, avoir + retour, date stockée sans décalage d'un jour.
- `tests/Unit/MoroccoLegalTimeTest.php` (12) : UTC+1 historique, Ramadan 2026, transition du 22/03/2026, frontière de date métier, organisations hors Maroc ; **garde de base de fuseaux** ; transition du 20/09/2026, heure répétée, novembre/décembre 2026, 1er janvier 2027, Ramadan 2027 et suites sans transition. Horloges gelées (`travelTo`/`travelBack`), instants UTC explicites.
- `tests/Concurrency/fiscal_year_boundary_race.php` (MySQL, vrais processus parallèles, barrière commune, horloge gelée par enfant) : facture / avoir / devis / retour ; scénario *cross-tz* (Casablanca → 2026, New York → 2025, UTC → 2025 au même instant 2025-12-31 23:30Z) et *straddle* (4 sessions avant + 4 après minuit UTC, même organisation). Vérifie succès, unicité, consécutivité par (organisation, année), année dans le numéro, 0 interblocage. Résultat : 20 contrôles PASS, interblocages +0.
- Tests hérités déplacés en UTC+1 : ils fixaient des dates en novembre 2026 ou à la frontière 30/09–01/10 avec « UTC+1 » dans l'attendu ; depuis le 20/09/2026 UTC et Casablanca partagent la date, donc ils ne pouvaient plus détecter un défaut UTC/local (et échouaient sur tzdata ≥ 2026c). Aucune assertion affaiblie : mêmes scénarios, instants en juin/juillet 2026.
Les instants de frontière d'année sont pris dans une période **réellement UTC+1** : un test à « 2027-01-01 00:30 » ne pourrait pas révéler le défaut.

## 15. Validation — preuves exactes
Code validé : `origin/staging` @ `d167960` + les 33 fichiers du Step 3 (arbre de travail exact du commit). Runtime : conteneur jetable `php:8.4-cli` (PHP 8.4.27, tzdata 2026.5), MySQL 8.4 jetable, bases `commerce_erp_*_test`. Aucune base persistante utilisée.
| Contrôle | Résultat |
|---|---|
| Documents, avant correctif | 12 tests : 4 réussis, 8 échecs |
| `scripts/check-timezone-runtime.php` | conteneur : exit 0 (11 sondes) ; poste Windows (tzdata 2025.2) : exit 1 (attendu) |
| **SQLite, suite complète** (inclut F-01, garde d'architecture Step 1, legal-time, documents) | **2333 tests, 17 963 assertions, 0 échec, 0 ignoré** |
| **MySQL 8.4 critique** (Finance, Payments, Documents, Sales, Pos, Tenancy, Sandbox, Returns, Exchanges, Mcp, Quotations, Attention, WorkCenter, Unit dont architecture) | **1246 tests, 10 148 assertions, 0 échec, 0 ignoré** |
| `fiscal_year_boundary_race.php` (4 sessions) | PASSED, 20 contrôles, interblocages +0 |
| `number_sequence_race.php` (4 sessions) | PASSED, interblocages +0 |
| Pint `--test` sur les 25 fichiers PHP modifiés/ajoutés | `passed` |
| `tsc --noEmit` | exit 0 |
| `vite build` (sortie temporaire) | exit 0 |
| Poste Windows (tzdata 2025.2) | garde `MoroccoLegalTimeTest` : échec volontaire + 6 ignorés — comportement voulu, inchangé |
| **GitHub Actions** | à consulter après ouverture de la PR (voir le rapport) |

## 16. Risques restants
1. **tzdata des conteneurs web/worker/planificateur staging et production inconnu** : si < 2026c, toutes les dates métier marocaines sont décalées d'une heure depuis le 20/09/2026. Bloquant avant usage réel ; vérification : `php scripts/check-timezone-runtime.php` (DEPLOYMENT_CHECKLIST §1).
2. La garde protège le CI, pas la production à l'exécution : une sonde de santé à l'exécution reste un complément utile (non fait).
3. `timezonedb-2026.6` est épinglé : à relever, avec la table de `check-timezone-runtime.php`, à la prochaine décision légale. L'installation PECL sur le runner GitHub n'a été qu'émulée localement (Ubuntu 24.04 + PPA) : le premier run CI de la PR la valide ou non.
4. Le défaut disparaît pour le Maroc tant que le régime GMT dure (UTC = local) ; il reste réel pour les autres fuseaux et tout changement légal futur.
5. Politique d'année du devis : année de **création** (voir §17).

## 17. Décisions comptables ou produit requises
1. Année de la numérotation d'un **devis** : date de création ou d'émission ?
2. Année d'un **avoir** : date d'émission (actuel) ou année de la facture d'origine ?
3. Facture postdatée : refus au-delà d'un horizon ?
4. Séquences sans année (BL, commandes, paiements) : confirmer qu'aucune obligation annuelle ne s'applique.
5. Nettoyage éventuel des données de staging (§12) : à approuver séparément.

## 18. Dépendances F-03 (Step 4)
Garde de période sur l'émission de facture (`invoice_date` locale), la création d'avoir (`credit_note_date`) et la position d'ouverture ; politique de réouverture ; cohérence « date métier / période comptable » des écritures datées explicitement.

## 19. Alignement Git
- Historique : la PR #24 (F-01) a été fusionnée dans `main` par erreur ; sa branche étant issue de `staging`, elle avait aussi apporté le Step 1. Correction : PR #25 (revert de la fusion, parent principal 1) fusionnée dans `main` (`5285c6c`) ; F-01 intégré dans `staging` par la PR #26 (`d167960`).
- Cette branche a été avancée en fast-forward sur `origin/staging` (`d167960`) sans toucher au travail non commité (aucun fichier commun avec F-01 ; sommes de contrôle des 33 fichiers vérifiées avant/après). Step 1, F-01 et F-02 sont tous présents dans le code validé.
- **Exigence de promotion (Phase 1 → `main`)** : `main` contient un revert de fusion. Une promotion `staging → main` ne rétablira **pas** automatiquement Step 1 ni F-01 (Git les considère déjà fusionnés). La release candidate doit d'abord annuler le revert (ou restaurer Step 1 + F-01 exactement une fois) puis vérifier l'arbre complet attendu.

## 20. Vérification du runtime de fuseaux sur staging (web, worker, planificateur)
À exécuter sur **chaque** conteneur PHP (aucun déploiement ni redémarrage n'est effectué par cette PR) :
1. `php scripts/check-timezone-runtime.php` → code retour 0, affiche `tzdb=` ≥ 2026c ; sinon liste des offsets erronés.
2. Contrôle manuel équivalent : `php -r '$t=new DateTimeZone("Africa/Casablanca"); echo timezone_version_get()," ",$t->getOffset(new DateTime("2026-11-17 12:00 UTC")),PHP_EOL;'` → offset `0`.
3. Vérifier séparément le **worker de file** et le **planificateur** (processus distincts, même image attendue) et, après toute mise à jour d'image, redémarrer web, worker et planificateur pour recharger la base.
Mise à jour et retour arrière : `DEPLOYMENT_CHECKLIST.md` §1. La vérification en **production** reste une exigence de release distincte.
