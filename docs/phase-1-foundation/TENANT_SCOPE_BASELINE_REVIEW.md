# Revue de sécurité des 65 constats de portée tenant (Phase 1 / Step 1 — revue finale)

> Lecture du code, sans exploitation, sans modification de code applicatif. Date : 2026-11-17. Les 65 constats sont ceux de la liste « UNREVIEWED BASELINE » du test `TenantScopingTest`.

## Résultat

| Classe | Constats |
|---|---|
| **Sûr, avec preuve** | 48 (dont 2 supprimés par la correction du détecteur → 46 entrées `REVIEWED`) |
| **À renforcer** | 14 |
| **À investiguer** | 3 |
| **Vulnérabilité confirmée** | **0** |

**Aucune vulnérabilité exploitable n'a été trouvée** : aucun identifiant fourni par l'utilisateur n'atteint une requête sans filtre d'organisation sans être revalidé (jeton secret, URL signée, session serveur, ou revalidation dans l'action en aval). Les 17 constats ouverts (14 + 3) sont de la défense en profondeur ou des chemins non tracés ; ils restent dans des listes **plafonnées** (`UNREVIEWED_CEILING = 3`, `NEEDS_STRENGTHENING_CEILING = 14`). Le plafond d'origine (65) n'a **pas** été augmenté : il a été remplacé par ces deux plafonds dont la somme (17) est inférieure à 65, chaque reclassement étant justifié ci-dessous.

Priorités demandées : MCP, paiements, POS, rapports financiers, sandbox, invitations, audit/activité — toutes couvertes ci-dessous (MCP : #15-17, #22-24, #43-55 ; paiements/rapports : #30-34 ; POS : #60-61 ; sandbox : #18, #62-64 ; invitations : #41-42 ; audit/activité : #65 et contrôleurs Notification/ActivityTimeline).

## Autres constats hors liste (vérifiés pendant la revue)

- `follow_ups.customer_id` / `quotation_id` utilisent une clé étrangère **simple** (pas composite `(organization_id, id)`) : la base n'empêche pas un lien inter-organisations ; l'application le valide à l'écriture (`SaveFollowUpAction` l.348/365). **À renforcer** (clé composite) — hors périmètre Step 1.
- `ActivityTimelineController` et `NotificationController` n'appellent pas d'autorisation explicite : `BusinessTimeline` filtre `organization_id` et applique les permissions par sujet ; `NotificationController` filtre `user_id` + `organization_id` (l.70-82). **Sûr**.
- `TenantContextController::store` renvoie 403 (et non 404) pour une boutique d'une autre organisation : l'existence de l'identifiant est révélée (**faible**). Correction minimale : répondre 404.

## Safe with evidence — Scanner blind spot (the chain IS scoped; the scanner cannot see a helper or an array argument)

Verified: both `upsertIdentifier()` callers put `organization_id` in `$key`; `FollowUpAssignmentReleaser::stale()` adds `{$table}.organization_id`; `StageProductCostImportAction` records carry `organization_id`.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 1 | `app/Actions/Catalog/StageProductCostImportAction.php` | `StageProductCostImportAction::execute` | ProductCostImportRow |
| 14 | `app/Actions/WooCommerce/SyncWooCommerceProductsAction.php` | `SyncWooCommerceProductsAction::upsertIdentifier` | ProductChannelIdentifier |
| 35 | `app/Services/FollowUps/FollowUpAssignmentReleaser.php` | `FollowUpAssignmentReleaser::staleAssignments` | FollowUp |
| 36 | `app/Services/FollowUps/FollowUpAssignmentReleaser.php` | `FollowUpAssignmentReleaser::staleRules` | FollowUpRecurringRule |

## Safe with evidence — Removed by the scanner fix (not exceptions any more)

`$records[] = ['organization_id' => …]` followed by `->insert($records)` is now recognised: #2 `StageProductImportAction`, #54 `McpProductOperationService::create`.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 2 | `app/Actions/Catalog/StageProductImportAction.php` | `StageProductImportAction::execute` | ProductImportRow |
| 54 | `app/Services/Mcp/Products/McpProductOperationService.php` | `McpProductOperationService::create` | McpOperationItem |

## Safe with evidence — System-wide scheduled jobs (by design)

Candidates are selected by the scheduler itself across organizations and re-locked by the id it just read; no request input.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 6 | `app/Actions/FollowUps/NotifyDueFollowUpsAction.php` | `NotifyDueFollowUpsAction::deliver` | FollowUp |
| 9 | `app/Actions/OrganizationBackups/DispatchDueOrganizationBackupsAction.php` | `DispatchDueOrganizationBackupsAction::execute` | OrganizationBackupSetting |

## Safe with evidence — Queued jobs: id from the application's own payload

Constructors receive ids dispatched by the application; the row's organization then scopes everything after.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 19 | `app/Jobs/CreateScheduledOrganizationBackupJob.php` | `CreateScheduledOrganizationBackupJob::handle` | OrganizationBackup |
| 20 | `app/Jobs/SyncOrganizationBackupToPersonalCloudJob.php` | `SyncOrganizationBackupToPersonalCloudJob::handle` | OrganizationBackupCloudCopy |
| 21 | `app/Jobs/SyncWooCommerceProductsJob.php` | `SyncWooCommerceProductsJob::failed` | WooCommerceSyncRun |
| 22 | `app/Jobs/ValidateMcpProductOperationJob.php` | `ValidateMcpProductOperationJob::handle` | McpOperation |
| 23 | `app/Jobs/ValidateMcpProductOperationJob.php` | `ValidateMcpProductOperationJob::failed` | McpOperation |
| 24 | `app/Jobs/ValidateMcpProductOperationJob.php` | `ValidateMcpProductOperationJob::markFailed` | McpOperation |
| 40 | `app/Services/Fulfillment/FulfillmentCustomerNotifier.php` | `FulfillmentCustomerNotifier::deliver` | FulfillmentCustomerNotification |

## Safe with evidence — Ids derived from organization-scoped queries in the same flow

`AttentionEpisodes::apply/closeUnconfirmed` (lines 49, 78 filter `organization_id`), `BusinessTimeline::page` (query at line ~145 filters `organization_id`, then re-reads those ids), reset executors/planner (balances selected with `organization_id` first), follow-up notifications (candidates are the system's own), `SaveFollowUpAction` validates customer/quotation/order/invoice organization on write (lines 348, 365, order lookup), POS allocations by the order's own lines.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 4 | `app/Actions/FollowUps/NotifyDueFollowUpsAction.php` | `NotifyDueFollowUpsAction::execute` | Customer |
| 5 | `app/Actions/FollowUps/NotifyDueFollowUpsAction.php` | `NotifyDueFollowUpsAction::execute` | Quotation |
| 7 | `app/Actions/FollowUps/SaveFollowUpAction.php` | `SaveFollowUpAction::store` | Store |
| 25 | `app/Services/Attention/AttentionEpisodes.php` | `AttentionEpisodes::apply` | AttentionItem |
| 26 | `app/Services/Attention/AttentionEpisodes.php` | `AttentionEpisodes::closeUnconfirmed` | AttentionItem |
| 28 | `app/Services/Attention/SalesOrderAttentionDetector.php` | `SalesOrderAttentionDetector::revisionStatuses` | SalesOrderRevision |
| 57 | `app/Services/OrganizationDataReset/OrganizationDataResetOperationQuery.php` | `OrganizationDataResetOperationQuery::deleteSalesReservationGraph` | InventoryBalance |
| 58 | `app/Services/OrganizationDataReset/Selective/SelectiveResetExecutor.php` | `SelectiveResetExecutor::release` | InventoryBalance |
| 59 | `app/Services/OrganizationDataReset/Selective/SelectiveResetPlanner.php` | `SelectiveResetPlanner::stateHash` | InventoryReservation |
| 61 | `app/Services/Pos/PosDraftLifecycle.php` | `PosDraftLifecycle::activeReservationIds` | SalesOrderInventoryAllocation |
| 65 | `app/Services/Timeline/BusinessTimeline.php` | `BusinessTimeline::page` | AuditLog |

## Safe with evidence — MCP authentication and binding

Bearer token is hash-checked (`hash_equals`), OAuth connection is bound to its client, document downloads require a signed URL (signature covers `connection` and `expires`) **and** the connection gate **and** the live permission, then re-read the document with `$connection->organization`. `McpConnectionRuntime` binds the connection id from the application's own operation row.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 15 | `app/Http/Controllers/Mcp/McpInvoiceDocumentController.php` | `McpInvoiceDocumentController::__invoke` | McpConnection |
| 16 | `app/Http/Controllers/Mcp/McpQuotationDocumentController.php` | `McpQuotationDocumentController::__invoke` | McpConnection |
| 17 | `app/Http/Middleware/AuthenticateMcpConnection.php` | `AuthenticateMcpConnection::handle` | McpConnection |
| 43 | `app/Services/Mcp/McpConnectionRuntime.php` | `McpConnectionRuntime::bind` | McpConnection |
| 44 | `app/Services/Mcp/McpConnectionRuntime.php` | `McpConnectionRuntime::attribute` | McpConnection |
| 45 | `app/Services/Mcp/OAuth/McpOAuthServer.php` | `McpOAuthServer::usableConnection` | McpConnection |

## Safe with evidence — Sandbox selection from the user's own session

`ResolveSandboxContext` / `SandboxStaleSession` read the generation of the sandbox id stored in the user's server-side session by `SandboxController` after its checks; used for the stale-generation fence and audit attribution only.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 18 | `app/Http/Middleware/ResolveSandboxContext.php` | `ResolveSandboxContext::handle` | Sandbox |
| 63 | `app/Services/Sandbox/SandboxStaleSession.php` | `SandboxStaleSession::forMissingRecord` | Sandbox |
| 64 | `app/Services/Sandbox/SandboxStaleSession.php` | `SandboxStaleSession::audit` | Sandbox |

## Safe with evidence — Invitations

Looked up by SHA-256 of a one-time secret token, then re-locked by its own key.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 41 | `app/Services/InvitationService.php` | `InvitationService::accept` | UserInvitation |
| 42 | `app/Services/InvitationService.php` | `InvitationService::findByToken` | UserInvitation |

## Safe with evidence — Notifications

De-duplication is keyed by the user's own id.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 56 | `app/Services/Notifications/NotificationPublisher.php` | `NotificationPublisher::publish` | UserNotification |

## Safe with evidence — MCP product-operation pipeline

Operations and items are created with `organization_id` from the connection; the only caller-supplied operation id goes through `McpProductOperationService::operation()` which filters `organization_id` and the connection user (line 736); the rest uses ids from the queue payload.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 46 | `app/Services/Mcp/Products/McpProductOperationProcessor.php` | `McpProductOperationProcessor::processChunk` | McpOperation |
| 47 | `app/Services/Mcp/Products/McpProductOperationProcessor.php` | `McpProductOperationProcessor::abandonChunk` | McpOperation |
| 48 | `app/Services/Mcp/Products/McpProductOperationProcessor.php` | `McpProductOperationProcessor::processItem` | McpOperationItem |
| 49 | `app/Services/Mcp/Products/McpProductOperationProcessor.php` | `McpProductOperationProcessor::failItem` | McpOperationItem |
| 50 | `app/Services/Mcp/Products/McpProductOperationProcessor.php` | `McpProductOperationProcessor::pendingQuery` | McpOperationItem |
| 51 | `app/Services/Mcp/Products/McpProductOperationProcessor.php` | `McpProductOperationProcessor::finalize` | McpOperation |
| 52 | `app/Services/Mcp/Products/McpProductOperationService.php` | `McpProductOperationService::execute` | McpOperation |
| 53 | `app/Services/Mcp/Products/McpProductOperationService.php` | `McpProductOperationService::view` | McpOperation |
| 55 | `app/Services/Mcp/Products/McpProductOperationService.php` | `McpProductOperationService::failValidation` | McpOperation |

## Safe with evidence — Stamp apposition

Called only from `Stamp*Action` after `authorizeInvoice/authorizeQuotation` on the stamped document.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 29 | `app/Services/DocumentStampApposer.php` | `DocumentStampApposer::apply` | DocumentStampApposition |

## Needs strengthening — Mutation / lock by bare key of an instance resolved earlier

Not exploitable today (the instance comes from a scoped binding/caller), but sibling actions also filter `organization_id` + `store_id`. Minimal fix: `->where('organization_id', $instance->organization_id)`. Includes the POS draft touch (#60).

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 3 | `app/Actions/Catalog/UpdateNonStockItemAction.php` | `UpdateNonStockItemAction::execute` | NonStockItem |
| 8 | `app/Actions/Inventory/PlanShowroomReplenishmentAction.php` | `PlanShowroomReplenishmentAction::execute` | TransferRequest |
| 11 | `app/Actions/Quotations/RemoveQuotationLineAction.php` | `RemoveQuotationLineAction::execute` | NonStockItem |
| 12 | `app/Actions/Quotations/SaveQuotationLineAction.php` | `SaveQuotationLineAction::syncUsageCounts` | NonStockItem |
| 13 | `app/Actions/Returns/CancelCustomerReturnAction.php` | `CancelCustomerReturnAction::execute` | CustomerReturn |
| 60 | `app/Services/Pos/PosDraftLifecycle.php` | `PosDraftLifecycle::touch` | SalesOrder |

## Needs strengthening — Financial aggregates selected only by sales_order_id

Payments/refunds/allocations are read by order ids with no `organization_id`. Today every caller derives ids from organization-scoped invoices/orders (callers listed: `FinanceInvoiceReadModel`, `FinanceCaEncaisseService`, `FinanceJournalService`, `FinanceInvoiceExcelExport`), so no leak is reachable; a future caller passing foreign ids would expose money figures. Minimal fix: pass the organization and add it to each query.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 30 | `app/Services/Finance/Export/FinanceInvoiceExcelExport.php` | `FinanceInvoiceExcelExport::writePaymentsSheet` | PaymentAllocation |
| 31 | `app/Services/Finance/FinanceJournalService.php` | `FinanceJournalService::paymentMethodsBySalesOrder` | PaymentAllocation |
| 32 | `app/Services/Finance/FinanceReceivablesService.php` | `FinanceReceivablesService::paidAmountsBySalesOrder` | PaymentRefund |
| 33 | `app/Services/Finance/FinanceReceivablesService.php` | `FinanceReceivablesService::paidAmountsBySalesOrder` | PaymentAllocation |
| 34 | `app/Services/Finance/FinanceReceivablesService.php` | `FinanceReceivablesService::orderedAllocationsBySalesOrder` | PaymentAllocation |

## Needs strengthening — Recurring rules / follow-up relocks

Rows come from organization-scoped lists in the same flow; relock by bare key. Minimal fix: add `organization_id`.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 37 | `app/Services/FollowUps/FollowUpRecurringGenerator.php` | `FollowUpRecurringGenerator::generateDue` | FollowUpRecurringRule |
| 38 | `app/Services/FollowUps/FollowUpRecurringGenerator.php` | `FollowUpRecurringGenerator::rescheduleOrganization` | FollowUpRecurringRule |
| 39 | `app/Services/FollowUps/FollowUpReminderSchedule.php` | `FollowUpReminderSchedule::realignDateOnly` | FollowUp |

## Requires further investigation — Callers/origin not traced

#10 `QueuePersonalCloudBackupSyncAction` (child lookup by backup id), #27 `AttentionOwnership::apply` (background update by item id), #62 `SandboxInventoryInitializer::ensure` (relock of the passed sandbox). Likely safe, **not proven**.

| # | Fichier | Méthode | Modèle |
|---|---|---|---|
| 10 | `app/Actions/OrganizationBackups/QueuePersonalCloudBackupSyncAction.php` | `QueuePersonalCloudBackupSyncAction::execute` | OrganizationBackupCloudCopy |
| 27 | `app/Services/Attention/AttentionOwnership.php` | `AttentionOwnership::apply` | AttentionItem |
| 62 | `app/Services/Sandbox/SandboxInventoryInitializer.php` | `SandboxInventoryInitializer::ensure` | Sandbox |

## Correction minimale recommandée (sans changement large)

Ajouter `->where('organization_id', …)` aux 14 requêtes « à renforcer » (changement mécanique, une ligne chacune), puis supprimer l'entrée correspondante et abaisser `NEEDS_STRENGTHENING_CEILING` dans le même commit. Tracer les 3 constats « à investiguer », puis les portée ou les documenter. Répondre 404 dans `TenantContextController::store`. Ces correctifs touchent du code applicatif : **non faits dans cette étape**.
