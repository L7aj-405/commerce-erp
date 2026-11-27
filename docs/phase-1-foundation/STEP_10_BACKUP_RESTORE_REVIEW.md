# Phase 1 — Step 10 : revue restauration / sauvegarde (S-08)

Source : `PHASE_1_IMPLEMENTATION_PLAN.md` ligne 10 (« Revue restauration/sauvegarde : lecture du code de restauration, test avec documents protégés, exercice chronométré », taille M, sans dépendance, critère « Procédure + test ») ; `SECURITY_AND_TENANCY_REVIEW.md` S-08 : la restauration doit être vérifiée contre l'écrasement de documents fiscaux protégés. Aucune migration, route ni UI.

## Lecture du code (`OrganizationBackupRestorer`)
- Garde-fous existants : permission `organization_backups.restore`, throttle `3,10`, reconfirmation de sécurité sur la route, archive signée/validée (organisation source = cible), préflight des états protégés (magasins, entrepôts, WooCommerce), instantané de sécurité pré-restauration, transaction unique avec rollback, FK différées, memberships jamais restaurées, audit `restore_started / restored / restore_failed`.
- **Défaut** : la restauration **remplace** toutes les tables restaurables de l'organisation (`deleteCurrentRows` puis `insert`), y compris `invoices`, `credit_notes`, `payments`, `payment_refunds`, `finance_outgoing_payments`, les séquences de numérotation et `finance_month_reviews`. Aucun appel à `FiscalDocumentProtection` (la définition utilisée par les remises à zéro). Restaurer une archive plus ancienne supprimait donc silencieusement des documents fiscaux émis depuis, faisait revenir leur statut (paiement annulé « réactivé », facture remplacée « ré-émise »), pouvait réutiliser des numéros (séquences ramenées en arrière) et rouvrait des mois clôturés. L'organisation en mode `protected` (défaut) était exposée ; le mode `test` ne l'est pas.

## Correction
`assertFiscalStatePreserved()` est ajoutée au préflight `assertProtectedStateCompatible()` — exécuté **avant** l'instantané et la mutation, puis **de nouveau dans la transaction de restauration, sous verrou**. Pour une organisation `protected`, la restauration est **refusée** (aucune donnée modifiée, audit `restore_failed`) si :
1. un document protégé actuel (facture émise/remplacée, avoir émis, paiement posté ou annulé, remboursement, paiement sortant ; périmètre exact de `FiscalDocumentProtection::protectedQuery`, Mode Test exclu) est absent de l'archive ou y porte un autre numéro ;
2. son statut ou son montant (comparaison décimale exacte) diffère dans l'archive ;
3. un mois aujourd'hui clôturé ne serait plus clôturé après restauration.
Organisation en mode `test` : aucune restriction (rien n'est protégé). Les documents d'une autre organisation ne comptent jamais (requêtes filtrées par `organization_id`). Les séquences n'ont pas de contrôle propre : si tous les documents protégés existent à l'identique dans l'archive, aucun numéro émis ne peut être réutilisé.

**Conséquence opérationnelle** : on ne peut plus « revenir en arrière » sur une organisation de production avec une sauvegarde antérieure à des documents fiscaux. Cas d'usage restant : restaurer une sauvegarde **postérieure ou égale** à l'état fiscal courant (ex. récupération après suppression de données non fiscales : produits, clients, stock), ou une organisation en mode `test`. Pour un vrai sinistre (base perdue), restaurer au niveau infrastructure (sauvegarde de base de données), pas par cette fonction.

## Procédure de restauration (exploitation)
1. Prévenir les utilisateurs ; geler les saisies (ou restaurer hors heures ouvrées).
2. Créer une sauvegarde manuelle **maintenant** (Paramètres → Sauvegardes) : c'est la référence de repli ; le restaurateur crée en plus un instantané pré-restauration (nom affiché dans l'audit `organization_backup.restored`).
3. Valider l'archive à restaurer (« Valider ») : signature, organisation, version.
4. Lancer la restauration (propriétaire, 2FA/reconfirmation). Si le message « Restauration refusée … documents fiscaux protégés » apparaît : **ne rien contourner** ; choisir une sauvegarde plus récente, ou escalader (restauration infra).
5. Après succès : exécuter `php artisan finance:reconcile {organisation} --json` (exit 0 attendu), vérifier les derniers numéros de facture/paiement et les mois clôturés, puis rouvrir les saisies.
6. Conserver l'instantané pré-restauration jusqu'à validation métier.

## Exercice chronométré
Mesuré par `OrganizationBackupFiscalProtectionTest::test_timed_restore_exercise_completes_and_reports_its_duration` (jeu seedé minimal : 1 commande, 1 facture, 1 paiement, stock) : SQLite (CI/test) sauvegarde ≈ 0,4 s, restauration complète (validation + instantané + remplacement) ≈ 1,0 s. Mesure MySQL : voir le rapport de livraison. **Ces durées ne représentent pas une organisation de production** : le coût est linéaire au nombre de lignes (préflight fiscal : une passe sur les documents protégés + lecture des tables de l'archive en mémoire id → 3 colonnes, plafonnée par `limits.rows_per_table`). Un exercice à volume réel doit être fait sur une copie de la base de pré-production.

## Tests (`tests/Feature/Settings/OrganizationBackupFiscalProtectionTest.php`, 7)
refus après paiement postérieur (rien supprimé, audit d'échec) ; refus après changement de statut d'un paiement/facture ; refus de réouverture d'un mois clos ; restauration validée quand les documents fiscaux sont identiques ; mode `test` non protégé ; isolation inter-organisations ; exercice chronométré. Les trois tests de refus échouent sans la correction.

## Limites connues
- Seuls les documents protégés par `FiscalDocumentProtection` sont comparés (pas les commandes, devis, retours, stock, commissions, qui restent restaurés tels quels).
- La comparaison charge en mémoire, par table protégée, `id → (numéro, statut, montant)` de l'archive.
- `fiscal_protection_started_at` : les documents antérieurs à cette date sont explicitement des données de test et ne sont pas protégés.
