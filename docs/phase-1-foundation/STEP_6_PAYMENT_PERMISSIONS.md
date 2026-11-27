# Phase 1 — Étape 6 : séparation `payments.refund` / `payments.reverse` (S-03)

Branche `fix/phase-1-step-6-payment-permissions` (base `origin/staging` @ `d8f512a`). Cible : staging uniquement.

## 1. Périmètre d'origine
`PHASE_1_IMPLEMENTATION_PLAN.md` ligne 6 : « Séparer `payments.refund` de `payments.reverse` + migration de provisionnement conservant les droits ; seuil d'approbation optionnel (typé) », finding **S-03** (gravité moyenne), test « matrice rôle × action ». Risque noté au plan : « risque de retirer un droit utilisé → provisionner par équivalence ».

## 2. Cause racine
Une seule permission, `payments.reverse`, gardait deux gestes différents : **annuler** un paiement (`ReversePaymentAction`, aucune sortie d'argent) et **rembourser** (`RefundPaymentAction`, `RefundCustomerReturnAction`, donc retours et échanges). Un rôle « annulation de saisie de caisse » pouvait donc décaisser de l'argent.

## 3. Points d'entrée audités
| Entrée | Geste réel | Avant | Après |
|---|---|---|---|
| `POST payments/{p}/reverse` → `PaymentPolicy::reverse` → `ReversePaymentAction` | annulation | `payments.reverse` | `payments.reverse` (inchangé) |
| `POST sales/returns/{r}/refunds` → `CustomerReturnPolicy::refund` → `RefundCustomerReturnAction` | remboursement | `payments.reverse` | **`payments.refund`** (politique + action) |
| `POST sales/exchanges/{e}/refunds` → `SettleCustomerExchangeRefundAction` → `RefundCustomerReturnAction` ; drapeau `can.refund` de la page | remboursement | `payments.reverse` | **`payments.refund`** |
| Annulation d'une commande payée → `CancelSalesOrderAction` + `SalesOrderPolicy::cancel` → `RefundPaymentAction` | **remboursement** (l'action rembourse chaque paiement) | `payments.reverse` | **`payments.refund`** |
| `RefundPaymentAction` (appel direct / tests) | remboursement | `payments.reverse` | **`payments.refund`** |
| Mode Test (`AuthorizesPaymentAction`) | liste blanche de permissions | `reverse` | `reverse` + `refund` (sinon le remboursement d'une commande Test serait refusé) |
| MCP | aucun outil de remboursement/annulation (lecture seule : `reversed_by` n'est qu'un champ lu) | — | inchangé ; un test interdit toute référence future sans passer par cette matrice |
| POS | ne rembourse ni n'annule | — | sans objet |

Isolation inchangée : chaque garde conserve ses contrôles organisation / boutique / environnement (Test) ; le test inter-organisations (§6) le vérifie avec les deux permissions détenues ailleurs.

## 4. Matrice avant / après
| Rôle | Annuler un paiement | Rembourser (retour / échange / annulation de commande payée) |
|---|---|---|
| Avant : détient `reverse` | oui | **oui** (couplé) |
| Après : `refund` seul | non | oui |
| Après : `reverse` seul | oui | **non** |
| Après : les deux | oui | oui |
| Après : aucun | non | non |
| Owner (`*`) / Admin par défaut / préréglage `general_admin` | oui | oui (les deux listes reçoivent `payments.refund`) |

## 5. Migration `2026_11_23_000001_provision_payments_refund_permission.php`
- Crée la permission (catalogue), puis **accorde `payments.refund` à chaque rôle qui détient `payments.reverse`** (système, préréglage ou personnalisé, toutes organisations) : droits effectifs identiques après migration.
- Ne touche **jamais** un rôle sans `payments.reverse`, et ne re-synchronise **pas** les rôles par défaut depuis la configuration (sinon un Admin dont l'organisation avait retiré `reverse` recevrait `refund` : escalade). Le `organization_id` copié est celui du rôle.
- Idempotente (vérifiée en l'exécutant deux fois) ; `down()` ne retire rien (historique conservé, même convention que les migrations de permissions précédentes).
- Un administrateur peut ensuite retirer `refund` ou `reverse` séparément via l'éditeur de rôles (garde anti-escalade existante).

## 6. Tests
`tests/Feature/Payments/PaymentPermissionMatrixTest.php` (15) : annulation, remboursement de retour, remboursement d'échange × {refund seul, reverse seul, les deux, aucun} en HTTP ; drapeau d'UI de l'échange ; Owner ; organisation étrangère (404, aucun remboursement) ; audit `payment.refunded` ; `refunded_by`.
`tests/Feature/Payments/PaymentPermissionProvisioningTest.php` (11) : annulation de commande payée et `RefundPaymentAction` × 4 rôles ; cohérence config (préréglages/rôles : refund ⇔ reverse) ; migration (équivalence, aucune escalade, Admin sans `reverse`, isolation organisation, idempotence) ; garde MCP.
Sur l'ancien code **13 de ces 26 tests échouent** (toutes les lignes « refund seul » / « reverse seul » qui doivent différer).

## 7. Seuil d'approbation des remboursements — NON implémenté (décision D-F4 ouverte)
Le plan dit « optionnel (typé) » ; `CONFIGURABLE_BUSINESS_ARCHITECTURE.md` le décrit en une ligne (`refund_approval` : « seuil au-delà duquel un second approbateur est requis », défaut « aucun seuil », modifiable par Owner/Finance) ; D-F4 : « Désactivé par défaut ». **Aucun** de ces documents ne définit le stockage (D-F2 « colonnes typées ou table générique » reste ouvert), le montant, l'identité/permission du second approbateur, l'état « en attente d'approbation », ni l'interface. Implémenter un flux maker-checker reviendrait à l'inventer ; un seuil sans flux d'approbation bloquerait les remboursements sans issue. Comportement livré = comportement par défaut du plan : **aucun seuil, aucun blocage** (couvert par les tests de remboursement). À trancher par le propriétaire : stockage (D-F2), qui approuve (permission dédiée ou `payments.refund` d'un autre utilisateur), état du remboursement en attente, valeur du seuil.

## 8. Décisions restantes
1. Seuil/approbation (ci-dessus).
2. Annuler une commande payée exige désormais `payments.refund` (c'est un remboursement). Aucun rôle existant n'est affecté (équivalence) ; pour les nouveaux rôles « annulation de commande » sans droit financier, le refus existait déjà.
3. Libellés d'interface : le nom de la permission est affiché tel quel dans l'éditeur de rôles (anglais, comme les autres) ; traduction à planifier avec l'UX.

## 9. UAT staging
1. Créer un rôle « Caisse » avec `payments.reverse` sans `payments.refund` : l'annulation d'un paiement réussit ; le bouton/POST de remboursement d'un retour et d'un échange est refusé (403) ; la page échange n'affiche pas le formulaire de remboursement.
2. Rôle avec `payments.refund` seul : remboursement de retour/échange OK ; annulation de paiement refusée.
3. Rôles existants (déjà `reverse`) : les deux gestes restent possibles après déploiement (migration).
4. Annuler une commande payée avec un rôle « reverse seul » : refus 403, commande inchangée.
5. Utilisateur d'une autre organisation : 404 sur les trois routes.
