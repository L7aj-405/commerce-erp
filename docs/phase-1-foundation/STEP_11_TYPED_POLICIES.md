# Phase 1 — Step 11 : squelette de politiques typées (D-F2)

Source : `PHASE_1_IMPLEMENTATION_PLAN.md` ligne 11 ; conception `CONFIGURABLE_BUSINESS_ARCHITECTURE.md` §2-§7. Dépend du Step 6 (permissions). Critère de sortie : **aucun changement de comportement** ; les changements de politique sont audités.

## Cause racine / périmètre
Deux règles métier lisaient une valeur d'environnement globale, non auditée, identique pour toutes les organisations : `config('payments.sales_order_payment_term_days')` (7 j, `ConfirmSalesOrderAction`) et `config('documents.invoice.payment_term_days')` (30 j, `IssueInvoiceAction`). Il n'existait aucun point unique de lecture des politiques ni de trace des changements. Ce step pose le **squelette** (décision D-F2 : colonnes typées + `PolicyResolver`, pas de table générique) sans rien brancher qui modifie un résultat.

## Conception
- **Colonnes typées sur `organizations`** (migration additive `2026_11_25_000001`) :
  - `invoice_issuance` (`after_full_payment` | `on_confirmation` | `on_delivery`, défaut `after_full_payment`) et `credit_limit_enforcement` (`warn` | `block` | `require_approval`, défaut `warn`) : **sans effet**, aucune Action ne les lit encore (enums fermés `App\Enums\InvoiceIssuancePolicy`, `CreditLimitEnforcementPolicy`).
  - `payment_terms` représentés par deux entiers bornés nullables : `sales_order_payment_term_days`, `invoice_payment_term_days` (0..365). `NULL` = hérite de la valeur d'environnement (7 / 30) : toutes les organisations existantes obtiennent exactement les mêmes échéances qu'avant.
- **`App\Services\Policies\PolicyResolver`** : lecteur unique, pur (aucune requête, aucun effet). Valeur invalide ou hors bornes en base -> défaut sûr. Le repli sur `config(...)` conserve l'historique `max(0, (int) ...)`. C'est la **seule** classe autorisée à lire ces deux clés (test d'architecture).
- **`App\Services\Policies\PolicyAudit`** : un événement `organization.policy_changed` par clé (ancien -> nouveau, motif, `effect = future_documents_only`) via `AuditLogger`.
- **`UpdateOrganizationPoliciesAction`** : seul point d'écriture (pas d'UI, pas de route). Contrôles : organisation = organisation active de l'acteur / connexion MCP (jamais fournie par le client) ; permission nominative par clé (`settings.billing.manage` pour `invoice_issuance` et les délais ; `customers.manage_credit` pour `credit_limit_enforcement`) ; motif obligatoire (5-500 car.) ; validation enum/bornes ; verrou de ligne + transaction ; valeur identique = aucun audit.
- **Effet futur uniquement** : les échéances sont posées une seule fois (`??=`) à la confirmation / l'émission ; un changement de politique n'altère jamais un document existant.
- `ConfirmSalesOrderAction` et `IssueInvoiceAction` appellent `PolicyResolver` (organisation du document).

## Migrations
| Migration | Contenu | Réversible |
|---|---|---|
| `2026_11_25_000001_add_typed_policy_columns_to_organizations` | 4 colonnes additives (défauts = comportement actuel) | oui (`dropColumn`) |
| `2026_11_25_000002_provision_policy_permissions` | catalogue `settings.billing.manage` / `customers.manage_credit` ; équivalence : chaque rôle détenant `settings.update` les reçoit (aucun autre rôle) ; idempotent ; échoue bruyamment si la config est en cache périmé | `down()` no-op volontaire (droits conservés) |
Le catalogue de config (`config/platform.php`) ajoute les deux clés au rôle `admin` par défaut et au preset `general_admin` ; Owner = `*`. Finance n'y a pas accès (pas de réglages en V1). Rien n'est resynchronisé sur les organisations existantes en dehors de la migration.

## Tests
- `tests/Feature/Policies/TypedPolicySkeletonTest.php` : défauts ; échéances identiques sans politique et avec surcharge égale à l'environnement ; l'environnement pilote toujours les organisations sans surcharge ; `invoice_issuance` / `credit_limit_enforcement` n'altèrent aucun résultat ; tables de décision du résolveur (enum, bornes, repli) ; validation enum/bornes/motif/clé inconnue ; audit ancien -> nouveau + motif ; valeur inchangée non auditée ; permission par clé ; isolation inter-organisations (y compris détenteur de permission en A avec B active) ; effet futur uniquement ; effacement d'une surcharge ; migration de provisionnement (équivalence, idempotence).
- `tests/Unit/Architecture/PolicyConfigArchitectureTest.php` : plus aucune lecture directe des deux clés dans `app/` hors résolveur.
- `AuditCoverageTest` reste vert (la nouvelle Action appelle `audit->record`).

## Limites / hors périmètre
- Aucune facturation à crédit/différée, aucun branchement de `invoice_issuance` ni de `credit_limit_enforcement` (Sprint 1+).
- Pas d'UI : l'Action est prête pour un contrôleur (qui ne fera que valider/autoriser puis appeler l'Action).
- Surcharge par client des conditions de paiement : non incluse (niveau organisation uniquement).
- `config/*` reste le repli ; les anciennes migrations de rattrapage (`2026_11_01…`, `2026_11_02…`) lisent encore la config : historique, exclues du test d'architecture.

## UAT Staging
1. Organisation existante, aucune action : confirmer une commande (échéance = date de vente + 7 j) et émettre une facture (échéance = date de facture + 30 j) : identiques à avant le déploiement.
2. Rôle Admin existant : vérifier la présence de `settings.billing.manage` et `customers.manage_credit` dans l'éditeur de rôles ; un rôle sans `settings.update` ne les a pas.
3. (Console/tinker, pas d'UI) via l'Action avec un compte autorisé : passer `sales_order_payment_term_days` à 15 avec motif ; vérifier l'événement `organization.policy_changed` (ancien `null` -> nouveau 15, motif, acteur) ; une nouvelle commande confirmée a 15 j, la commande déjà confirmée garde son échéance.
4. Compte sans la permission, ou autre organisation active : refus 403, aucune ligne d'audit.
5. Valeur hors ensemble (`invoice_issuance = never`, jours = 366) : rejet de validation. Remettre la surcharge à `null` : retour à 7 / 30 j.
6. Positionner `invoice_issuance = on_delivery` : une facture reste émissible dans les mêmes conditions qu'avant (aucun effet).
