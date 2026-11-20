# Phase 1 — Étape 12 : UX de fiabilité (U-1 … U-5)

> Branche `fix/phase-1-step-12`, depuis `origin/staging` @ `ec066ac`. Aucune règle métier, formule financière, route ni permission n'est modifiée.
> Plan : `PHASE_1_IMPLEMENTATION_PLAN.md` ligne 12 ; exigences : `UX_SIMPLICITY_REQUIREMENTS.md` U-1…U-5. Critère de sortie : **revue utilisateur [Client]**.

## 1. Périmètre et garde-fous
Présentation uniquement : libellés, définitions au survol (`InfoTip` existant), un champ **additif** MCP, un message de refus plus explicite. Même clé de validation (`invoice`), même statut (422 / erreur de session), même règle de blocage. Aucun chiffre inventé : un état « estimé / incomplet » n'est affiché que sur une condition réelle.

## 2. Changements par exigence

| Exig. | Changement | Fichiers principaux |
|---|---|---|
| **U-1** | Fiche client : chaque tuile a une définition (survol / focus / clic) ; état **Incomplet** quand le chiffre est masqué par les droits (`null` renvoyé par `CustomerFinancialSummary`) ou quand « Solde des factures émises » s'affiche sans le non-facturé (droit manquant : « 0 » ≠ « ne doit rien ») ; état **Estimé** sur « Reste dû sur commandes non facturées » > 0 (montant définitif à la facture). Nouvelle tuile « À rembourser au client » seulement si `refund_obligation > 0` (champ déjà présent dans les props). Page Créances : total + définition (reste dû des commandes confirmées, facturées ou non ; ≠ solde des factures émises de la fiche client). FR + AR. | `Sales/Customers/Show.tsx`, `Finance/Creances.tsx`, `components/ui/ReliabilityBadge.tsx`, `i18n/translations.ts` |
| **U-2** | `IssueInvoiceAction::FULL_PAYMENT_REQUIRED_MESSAGE` : même première phrase + raison métier (« document comptable définitif… vente entièrement encaissée ») + action (« Enregistrer un paiement » puis émettre). Page facture : texte d'aide FR/AR et lien « Enregistrer un paiement » vers la commande **uniquement** si `can.recordPayment` (policy `PaymentPolicy::create`) **et** `can.viewOrder`. L'émission MCP garde son code `sales_order_not_fully_paid` (aucun usage du message web côté MCP). | `IssueInvoiceAction.php`, `InvoiceController.php`, `Invoices/Show.tsx` |
| **U-3** | Textes d'aide distincts : « Annuler un paiement » (corriger une saisie erronée, aucun argent rendu) vs « Remboursement » (rendre de l'argent : retour, échange, commande payée annulée ; droit `payments.refund`). Affichés sur la page paiement. Permissions inchangées (la séparation `reverse`/`refund` vient de l'étape 6). | `Payments/Show.tsx`, `i18n/translations.ts` |
| **U-4** | Champ additif `production_warning` dans **toute** réponse `preview_*` / `prepare_*` MCP (tous les outils dont le nom commence par `preview_` ou `prepare_`), injecté au point unique `execute()` de chaque classe d'outils via `McpProductionNotice::annotate()`. Aucune clé renommée. Alerte « écrit en PRODUCTION, pas de mode test » sur l'écran Réglages > IA/MCP, dans le dialogue de création de connexion et sur l'écran d'autorisation OAuth. | `app/Services/Mcp/McpProductionNotice.php` + 16 classes d'outils, `Settings/AiMcp.tsx`, `AiMcpAuthorize.tsx` |
| **U-5** | Éditeur de rôles : panneau « Ce que ce rôle peut faire » (mis à jour en direct) et alerte de séparation des tâches si le rôle cumule `payments.create` + `payments.reverse` + `payments.refund` ; pastille d'alerte sur les cartes de rôles. Mapping de présentation dans un **nouveau** fichier `app/Support/Roles/RoleCapabilitySummary.php` (envoyé en prop Inertia `roleCapabilities` ; le navigateur applique les mêmes règles « au moins un droit » / « toutes les capacités »). `config/platform.php` **non modifié**. | `RoleCapabilitySummary.php`, `UsersAccessController.php`, `RoleEditor.tsx`, `Index.tsx` |

## 3. Tests
- Unit : `tests/Unit/RoleCapabilitySummaryTest.php` (conflit à 3 droits, 2 sur 3 = pas de conflit, vue seule = rien, doublons) ; `tests/Unit/Mcp/McpProductionNoticeTest.php`.
- Feature : `McpFinanceToolsTest::test_write_previews_carry_the_production_warning…` (previewPayment + previewInvoiceIssue : champ ajouté, clés existantes intactes) ; `InvoiceLifecycleTest` (attentes mises à jour sur la constante + test du message actionnable) ; `RoleCapabilityMappingTest` (chaque permission du mapping existe dans `config/platform.php`, libellés FR+AR présents, prop Inertia) ; `ReliabilityUxPropsTest` (props : chiffres `null` sans droit paiement ; `can.recordPayment`).
- `npx tsc --noEmit` et `npm run build`.
- Résultats chiffrés : voir le rapport de livraison (non recopiés ici pour ne pas figer un état).

## 4. Preuve navigateur vs preuve base / tests
| Élément | Preuve disponible |
|---|---|
| Message de refus, champ `production_warning`, props `roleCapabilities` / `can.recordPayment` / chiffres `null`, existence des clés i18n FR+AR, permissions du mapping | **Tests automatisés** (SQLite) |
| Typage TS, compilation du bundle | `tsc --noEmit`, `vite build` |
| Rendu des infobulles, contraste clair/sombre, mise en page RTL (AR), ordre de tabulation, alerte de rôle en direct | **Aucune session navigateur** : à valider en UAT [Navigateur] |
| Compréhension par un comptable débutant (critère §5.1 des exigences) | [Client] à valider avec des utilisateurs |

## 5. Scénarios UAT (staging)
1. **Facture refusée** : commande confirmée non soldée, brouillon de facture → Émettre : message complet (raison + « Enregistrer un paiement ») ; avec droit paiement, le lien ouvre la commande ; avec un compte sans `payments.create`, pas de lien.
2. **Fiche client** : survoler/focuser chaque « i » : définition lisible FR et AR. Avec un rôle sans `payments.view` : badge « Incomplet » sur Encaissé / Solde ; avec commandes non facturées : badge « Estimé ».
3. **Créances** : l'info-bulle du total explique la différence avec la fiche client.
4. **Paiement** : page d'un paiement comptabilisé : aide « Annuler » vs « Remboursement » ; section remboursements : aide dédiée.
5. **MCP** : écran Réglages > IA/MCP et dialogue « Créer » : alerte de production visible ; appeler un aperçu (ex. `preview_payment`) : `data.production_warning` présent.
6. **Rôles** : cocher Encaisser + Annuler + Rembourser : alerte immédiate et résumé ; décocher un des trois : l'alerte disparaît ; la carte du rôle existant affiche la pastille. Vérifier qu'aucun droit des rôles par défaut n'a changé.

## 6. Risques / limites
- « Estimé / incomplet » n'existe que là où une condition réelle le justifie ; le reste du produit (tableaux de bord) n'est pas étiqueté dans cette étape.
- Le texte du refus de facture contient désormais le libellé « Enregistrer un paiement » : si le bouton est renommé, mettre à jour `IssueInvoiceAction::FULL_PAYMENT_REQUIRED_MESSAGE` (le test verrouille la chaîne).
- Les clients MCP à schéma de sortie strict sur `data` : `data` est un `object` libre dans le schéma publié ; une clé est ajoutée (pas de renommage).
