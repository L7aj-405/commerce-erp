<?php

/**
 * Documented exceptions to the tenant-scoping architecture test (TenantScopingTest).
 *
 * A key is `<repo-relative file>::<Class::method>::<tenant model>`. An entry means: "this query on tenant-owned data
 * does not name `organization_id` in its call chain". Two kinds of reason:
 *
 *  - REVIEWED: a reviewer checked that no other organization's row can be reached, and wrote why;
 *  - UNREVIEWED BASELINE: grandfathered debt, NOT an endorsement. The test keeps a ratchet (BASELINE_CEILING in
 *    TenantScopingTest): the baseline may only shrink.
 *
 * The test FAILS when an entry no longer matches a real finding (the list cannot rot) and when a NEW unscoped
 * query appears (it cannot silently grow). Even REVIEWED RELOCK / IDS entries are hardening candidates: prefer
 * adding the organization_id filter and deleting the entry (application work outside Phase 1 / Step 1).
 */


$r1 = 'REVIEWED (final review) - safe, scanner blind spot. The call chain is scoped but the scanner cannot see it: the helper/array argument adds organization_id (e.g. FollowUpAssignmentReleaser::stale(), or the $key array of both upsertIdentifier() callers).';
$r2 = 'NEEDS STRENGTHENING. Mutates or locks a row by its bare key taken from an instance resolved earlier (route binding / caller). Not exploitable today, but sibling actions also filter organization_id and store_id here. Minimal fix: add ->where(\'organization_id\', $instance->organization_id).';
$r3 = 'REVIEWED (final review) - safe, system-wide scheduled job by design: it iterates the rows of every organization on purpose (scheduler candidates) and re-locks a row it just selected itself; no request input is involved.';
$r4 = 'REVIEWED (final review) - safe, ids come from queries that already filter organization_id earlier in the same flow (AttentionEpisodes/Timeline/Reset/Follow-up candidates, or the order\'s own lines), and SaveFollowUpAction validates customer/quotation/order organization on write. Hardening candidate: add organization_id.';
$r5 = 'REVIEWED. Reads rows by ids taken from records that were already organization-scoped earlier in the same flow (no user-supplied id reaches this query). Hardening candidate: add organization_id.';
$r7 = 'REVIEWED. Re-locks or re-reads an instance that the caller already resolved through an organization-scoped route binding, query or action; the key used is that instance\'s own key. Hardening candidate: add where(organization_id).';
$r8 = 'REVIEWED. Correlated sub-select (whereColumn on the outer row): it only sees rows related to the outer Invoice row, and the outer Invoice query is organization-scoped.';
$r9 = 'REVIEWED (final review) - safe, MCP authentication/binding: the connection is resolved from a hash-checked bearer token, an OAuth token row, a signed URL (signature covers connection+expiry) or the application\'s own operation row; McpConnectionGate then checks status, expiry, organization and membership, and every document read re-filters by the connection\'s organization.';
$r10 = 'REVIEWED. The id comes from the request but the Action that receives the model re-queries it with organization_id, store_id and the order allocation (RefundCustomerReturnAction), so another organization\'s row yields 404. Defence-in-depth gap only.';
$r11 = 'REVIEWED. Resolves the sandbox from the id stored in the user\'s own session (audit/leave only); no request-supplied id is used.';
$r12 = 'REVIEWED. Authorisation happens immediately after the lookup (ActiveTenantContext::activateStore aborts 403 unless the store belongs to the active organization and the user is a member).';
$r13 = 'REVIEWED (final review) - safe, sandbox id comes from the user\'s own server-side session selection (written by SandboxController only after the organization/user checks); it is used for generation checks and audit attribution only.';
$r14 = 'REVIEWED (final review) - safe, queued job reads a row by the id the application put in its own payload (never user input); the row\'s organization is then used for every follow-up query.';
$r15 = 'REVIEWED. Queued job re-reads a record by the id the application itself put in the job payload (no user input); the row\'s organization is then used for every follow-up query.';
$r16 = 'REVIEWED (final review) - safe, polymorphic stamp lookup keyed by the stamped document itself; DocumentStampApposer is only called from the Stamp*Action classes after authorizeInvoice/authorizeQuotation on that document.';
$r17 = 'NEEDS STRENGTHENING. Financial aggregate over payments/refunds/allocations selected only by sales_order_id (no organization_id on the payments join). Safe today because every caller passes ids derived from organization-scoped invoices/orders, but a future caller passing foreign ids would leak money figures. Minimal fix: add organization_id to the queries and to the method signature.';
$r18 = 'REVIEWED. Membership lookup keyed by user id plus a store resolved from the active organization; a user\'s membership rows cannot belong to another organization\'s store.';
$r19 = 'NEEDS STRENGTHENING. Recurring-rule/follow-up row locked or updated by bare key (rows come from an organization-scoped list in the same flow). Minimal fix: add organization_id to the relock query.';
$r20 = 'REVIEWED (final review) - safe, invitation is found by the SHA-256 hash of a secret one-time token (not guessable) and then re-locked by its own key.';
$r21 = 'REVIEWED (final review) - safe, MCP product-operation pipeline: operations/items are created with organization_id from the connection, processed by ids from the application\'s own queue payload, and the only caller-supplied operation id goes through McpProductOperationService::operation() which filters organization_id and the connection user (line 736).';
$r22 = 'REVIEWED (final review) - safe, notification de-duplication is keyed by the user\'s own id (user_id + dedupe key); notifications are per user.';
$rTraced = "REVIEWED (final review, traced) - safe. #10: OrganizationBackupCloudController::copy checks the permission and that the backup belongs to the active organization (404 otherwise) before QueuePersonalCloudBackupSyncAction, which filters the connection by organization_id; the scheduled path uses the system's own backup. #27: AttentionOwnership::apply() is private and is only called from repairOrganization(), whose chunk query filters organization_id. #62: SandboxInventoryInitializer::ensure() receives a sandbox found with organization_id (SandboxController::enter) or resolved by SandboxContext::resolve(), which requires the session's user_id and organization_id to match, the sandbox feature and permissions, and loads the sandbox with organization_id.";

return [
    'app/Actions/Catalog/StageProductCostImportAction.php::App\\Actions\\Catalog\\StageProductCostImportAction::execute::ProductCostImportRow' => $r1,
    'app/Actions/Catalog/UpdateNonStockItemAction.php::App\\Actions\\Catalog\\UpdateNonStockItemAction::execute::NonStockItem' => $r2,
    'app/Actions/FollowUps/NotifyDueFollowUpsAction.php::App\\Actions\\FollowUps\\NotifyDueFollowUpsAction::deliver::FollowUp' => $r3,
    'app/Actions/FollowUps/NotifyDueFollowUpsAction.php::App\\Actions\\FollowUps\\NotifyDueFollowUpsAction::execute::Customer' => $r4,
    'app/Actions/FollowUps/NotifyDueFollowUpsAction.php::App\\Actions\\FollowUps\\NotifyDueFollowUpsAction::execute::Quotation' => $r4,
    'app/Actions/FollowUps/RecordShipmentFollowUpOutcomeAction.php::App\\Actions\\FollowUps\\RecordShipmentFollowUpOutcomeAction::execute::Store' => $r5,
    'app/Actions/FollowUps/SaveFollowUpAction.php::App\\Actions\\FollowUps\\SaveFollowUpAction::store::Store' => $r4,
    'app/Actions/FollowUps/TransitionFollowUpAction.php::App\\Actions\\FollowUps\\TransitionFollowUpAction::execute::Store' => $r5,
    'app/Actions/Inventory/PlanShowroomReplenishmentAction.php::App\\Actions\\Inventory\\PlanShowroomReplenishmentAction::execute::TransferRequest' => $r2,
    'app/Actions/OrganizationBackups/DispatchDueOrganizationBackupsAction.php::App\\Actions\\OrganizationBackups\\DispatchDueOrganizationBackupsAction::execute::OrganizationBackupSetting' => $r3,
    'app/Actions/OrganizationBackups/QueuePersonalCloudBackupSyncAction.php::App\\Actions\\OrganizationBackups\\QueuePersonalCloudBackupSyncAction::execute::OrganizationBackupCloudCopy' => $rTraced,
    'app/Actions/Procurement/CreateProcurementAction.php::App\\Actions\\Procurement\\CreateProcurementAction::execute::SalesOrder' => $r7,
    'app/Actions/Procurement/ReportOutOfStockArticleAction.php::App\\Actions\\Procurement\\ReportOutOfStockArticleAction::execute::SalesOrder' => $r7,
    'app/Actions/Quotations/RemoveQuotationLineAction.php::App\\Actions\\Quotations\\RemoveQuotationLineAction::execute::NonStockItem' => $r2,
    'app/Actions/Quotations/SaveQuotationLineAction.php::App\\Actions\\Quotations\\SaveQuotationLineAction::syncUsageCounts::NonStockItem' => $r2,
    'app/Actions/Returns/CancelCustomerReturnAction.php::App\\Actions\\Returns\\CancelCustomerReturnAction::execute::CustomerReturn' => $r2,
    'app/Actions/Sales/CancelSalesOrderAction.php::App\\Actions\\Sales\\CancelSalesOrderAction::execute::SalesOrder' => $r7,
    'app/Actions/Sales/ConfirmSalesOrderAction.php::App\\Actions\\Sales\\ConfirmSalesOrderAction::execute::SalesOrder' => $r7,
    'app/Actions/Sales/RemoveSalesOrderLineAction.php::App\\Actions\\Sales\\RemoveSalesOrderLineAction::execute::SalesOrder' => $r7,
    'app/Actions/Sales/RemoveSalesOrderLineAction.php::App\\Actions\\Sales\\RemoveSalesOrderLineAction::execute::SalesOrderLine' => $r7,
    'app/Actions/Sales/SaveSalesOrderLineAction.php::App\\Actions\\Sales\\SaveSalesOrderLineAction::execute::SalesOrder' => $r7,
    'app/Actions/Sales/UpdateSalesOrderAction.php::App\\Actions\\Sales\\UpdateSalesOrderAction::execute::SalesOrder' => $r7,
    'app/Actions/WooCommerce/SyncWooCommerceProductsAction.php::App\\Actions\\WooCommerce\\SyncWooCommerceProductsAction::upsertIdentifier::ProductChannelIdentifier' => $r1,
    'app/Http/Controllers/Documents/InvoiceController.php::App\\Http\\Controllers\\Documents\\InvoiceController::index::CreditNote' => $r8,
    'app/Http/Controllers/Integrations/WooCommerceIntegrationController.php::App\\Http\\Controllers\\Integrations\\WooCommerceIntegrationController::index::WooCommerceSyncRun' => $r5,
    'app/Http/Controllers/Integrations/WooCommerceIntegrationController.php::App\\Http\\Controllers\\Integrations\\WooCommerceIntegrationController::runStatus::WooCommerceSyncRun' => $r5,
    'app/Http/Controllers/Integrations/WooCommerceIntegrationController.php::App\\Http\\Controllers\\Integrations\\WooCommerceIntegrationController::sync::WooCommerceSyncRun' => $r5,
    'app/Http/Controllers/Mcp/McpInvoiceDocumentController.php::App\\Http\\Controllers\\Mcp\\McpInvoiceDocumentController::__invoke::McpConnection' => $r9,
    'app/Http/Controllers/Mcp/McpQuotationDocumentController.php::App\\Http\\Controllers\\Mcp\\McpQuotationDocumentController::__invoke::McpConnection' => $r9,
    'app/Http/Controllers/PosController.php::App\\Http\\Controllers\\PosController::storeDraft::Store' => $r7,
    'app/Http/Controllers/PosController.php::App\\Http\\Controllers\\PosController::storeDraftLine::SalesOrder' => $r7,
    'app/Http/Controllers/Sales/CustomerReturnController.php::App\\Http\\Controllers\\Sales\\CustomerReturnController::refund::Payment' => $r10,
    'app/Http/Controllers/SandboxController.php::App\\Http\\Controllers\\SandboxController::leave::Sandbox' => $r11,
    'app/Http/Controllers/TenantContextController.php::App\\Http\\Controllers\\TenantContextController::store::Store' => $r12,
    'app/Http/Middleware/AuthenticateMcpConnection.php::App\\Http\\Middleware\\AuthenticateMcpConnection::handle::McpConnection' => $r9,
    'app/Http/Middleware/ResolveSandboxContext.php::App\\Http\\Middleware\\ResolveSandboxContext::handle::Sandbox' => $r13,
    'app/Jobs/CreateScheduledOrganizationBackupJob.php::App\\Jobs\\CreateScheduledOrganizationBackupJob::handle::OrganizationBackup' => $r14,
    'app/Jobs/SyncOrganizationBackupToPersonalCloudJob.php::App\\Jobs\\SyncOrganizationBackupToPersonalCloudJob::handle::OrganizationBackupCloudCopy' => $r14,
    'app/Jobs/SyncWooCommerceProductsJob.php::App\\Jobs\\SyncWooCommerceProductsJob::failed::WooCommerceIntegration' => $r15,
    'app/Jobs/SyncWooCommerceProductsJob.php::App\\Jobs\\SyncWooCommerceProductsJob::failed::WooCommerceSyncRun' => $r14,
    'app/Jobs/SyncWooCommerceProductsJob.php::App\\Jobs\\SyncWooCommerceProductsJob::handle::WooCommerceIntegration' => $r15,
    'app/Jobs/ValidateMcpProductOperationJob.php::App\\Jobs\\ValidateMcpProductOperationJob::failed::McpOperation' => $r14,
    'app/Jobs/ValidateMcpProductOperationJob.php::App\\Jobs\\ValidateMcpProductOperationJob::handle::McpOperation' => $r14,
    'app/Jobs/ValidateMcpProductOperationJob.php::App\\Jobs\\ValidateMcpProductOperationJob::markFailed::McpOperation' => $r14,
    'app/Services/Attention/AttentionEpisodes.php::App\\Services\\Attention\\AttentionEpisodes::apply::AttentionItem' => $r4,
    'app/Services/Attention/AttentionEpisodes.php::App\\Services\\Attention\\AttentionEpisodes::closeUnconfirmed::AttentionItem' => $r4,
    'app/Services/Attention/AttentionOwnership.php::App\\Services\\Attention\\AttentionOwnership::apply::AttentionItem' => $rTraced,
    'app/Services/Attention/SalesOrderAttentionDetector.php::App\\Services\\Attention\\SalesOrderAttentionDetector::revisionStatuses::SalesOrderRevision' => $r4,
    'app/Services/Commissions/CommissionRuleManager.php::App\\Services\\Commissions\\CommissionRuleManager::activate::CommissionRuleSet' => $r7,
    'app/Services/DocumentStampApposer.php::App\\Services\\DocumentStampApposer::apply::DocumentStampApposition' => $r16,
    'app/Services/Finance/Export/FinanceInvoiceExcelExport.php::App\\Services\\Finance\\Export\\FinanceInvoiceExcelExport::writePaymentsSheet::PaymentAllocation' => $r17,
    'app/Services/Finance/FinanceCaEncaisseService.php::App\\Services\\Finance\\FinanceCaEncaisseService::linesBySalesOrder::SalesOrderLine' => $r5,
    'app/Services/Finance/FinanceInvoiceReadModel.php::App\\Services\\Finance\\FinanceInvoiceReadModel::withPaymentSummaries::CreditNote' => $r5,
    'app/Services/Finance/FinanceJournalService.php::App\\Services\\Finance\\FinanceJournalService::events::CreditNote' => $r5,
    'app/Services/Finance/FinanceJournalService.php::App\\Services\\Finance\\FinanceJournalService::events::Invoice' => $r5,
    'app/Services/Finance/FinanceJournalService.php::App\\Services\\Finance\\FinanceJournalService::events::PaymentRefund' => $r5,
    'app/Services/Finance/FinanceJournalService.php::App\\Services\\Finance\\FinanceJournalService::paymentMethodsBySalesOrder::PaymentAllocation' => $r17,
    'app/Services/Finance/FinanceReceivablesService.php::App\\Services\\Finance\\FinanceReceivablesService::orderedAllocationsBySalesOrder::PaymentAllocation' => $r17,
    'app/Services/Finance/FinanceReceivablesService.php::App\\Services\\Finance\\FinanceReceivablesService::paidAmountsBySalesOrder::PaymentAllocation' => $r17,
    'app/Services/Finance/FinanceReceivablesService.php::App\\Services\\Finance\\FinanceReceivablesService::paidAmountsBySalesOrder::PaymentRefund' => $r17,
    'app/Services/FollowUps/FollowUpAccess.php::App\\Services\\FollowUps\\FollowUpAccess::hasStoreAccess::StoreMembership' => $r18,
    'app/Services/FollowUps/FollowUpAssignmentReleaser.php::App\\Services\\FollowUps\\FollowUpAssignmentReleaser::release::Store' => $r5,
    'app/Services/FollowUps/FollowUpAssignmentReleaser.php::App\\Services\\FollowUps\\FollowUpAssignmentReleaser::staleAssignments::FollowUp' => $r1,
    'app/Services/FollowUps/FollowUpAssignmentReleaser.php::App\\Services\\FollowUps\\FollowUpAssignmentReleaser::staleRules::FollowUpRecurringRule' => $r1,
    'app/Services/FollowUps/FollowUpRecurringGenerator.php::App\\Services\\FollowUps\\FollowUpRecurringGenerator::generateDue::FollowUpRecurringRule' => $r19,
    'app/Services/FollowUps/FollowUpRecurringGenerator.php::App\\Services\\FollowUps\\FollowUpRecurringGenerator::rescheduleOrganization::FollowUpRecurringRule' => $r19,
    'app/Services/FollowUps/FollowUpReminderSchedule.php::App\\Services\\FollowUps\\FollowUpReminderSchedule::realignDateOnly::FollowUp' => $r19,
    'app/Services/Fulfillment/FulfillmentCustomerNotifier.php::App\\Services\\Fulfillment\\FulfillmentCustomerNotifier::deliver::FulfillmentCustomerNotification' => $r14,
    'app/Services/InvitationService.php::App\\Services\\InvitationService::accept::UserInvitation' => $r20,
    'app/Services/InvitationService.php::App\\Services\\InvitationService::findByToken::UserInvitation' => $r20,
    'app/Services/Mcp/Documents/McpDocumentStampService.php::App\\Services\\Mcp\\Documents\\McpDocumentStampService::find::Invoice' => $r7,
    'app/Services/Mcp/McpConnectionRuntime.php::App\\Services\\Mcp\\McpConnectionRuntime::attribute::McpConnection' => $r9,
    'app/Services/Mcp/McpConnectionRuntime.php::App\\Services\\Mcp\\McpConnectionRuntime::bind::McpConnection' => $r9,
    'app/Services/Mcp/OAuth/McpOAuthServer.php::App\\Services\\Mcp\\OAuth\\McpOAuthServer::usableConnection::McpConnection' => $r9,
    'app/Services/Mcp/Procurement/McpProcurementReceiptService.php::App\\Services\\Mcp\\Procurement\\McpProcurementReceiptService::preview::SalesOrder' => $r5,
    'app/Services/Mcp/Procurement/McpProcurementReceiptService.php::App\\Services\\Mcp\\Procurement\\McpProcurementReceiptService::record::SalesOrder' => $r5,
    'app/Services/Mcp/Procurement/McpPurchaseOrderService.php::App\\Services\\Mcp\\Procurement\\McpPurchaseOrderService::create::PurchaseOrder' => $r7,
    'app/Services/Mcp/Products/McpProductOperationProcessor.php::App\\Services\\Mcp\\Products\\McpProductOperationProcessor::abandonChunk::McpOperation' => $r21,
    'app/Services/Mcp/Products/McpProductOperationProcessor.php::App\\Services\\Mcp\\Products\\McpProductOperationProcessor::failItem::McpOperationItem' => $r21,
    'app/Services/Mcp/Products/McpProductOperationProcessor.php::App\\Services\\Mcp\\Products\\McpProductOperationProcessor::finalize::McpOperation' => $r21,
    'app/Services/Mcp/Products/McpProductOperationProcessor.php::App\\Services\\Mcp\\Products\\McpProductOperationProcessor::pendingQuery::McpOperationItem' => $r21,
    'app/Services/Mcp/Products/McpProductOperationProcessor.php::App\\Services\\Mcp\\Products\\McpProductOperationProcessor::processChunk::McpOperation' => $r21,
    'app/Services/Mcp/Products/McpProductOperationProcessor.php::App\\Services\\Mcp\\Products\\McpProductOperationProcessor::processItem::McpOperationItem' => $r21,
    'app/Services/Mcp/Products/McpProductOperationService.php::App\\Services\\Mcp\\Products\\McpProductOperationService::execute::McpOperation' => $r21,
    'app/Services/Mcp/Products/McpProductOperationService.php::App\\Services\\Mcp\\Products\\McpProductOperationService::failValidation::McpOperation' => $r21,
    'app/Services/Mcp/Products/McpProductOperationService.php::App\\Services\\Mcp\\Products\\McpProductOperationService::view::McpOperation' => $r21,
    'app/Services/Mcp/Sales/McpSalesOrderService.php::App\\Services\\Mcp\\Sales\\McpSalesOrderService::result::ProductVariant' => $r5,
    'app/Services/MembershipService.php::App\\Services\\MembershipService::addStoreMember::StoreMembership' => $r18,
    'app/Services/Notifications/NotificationPublisher.php::App\\Services\\Notifications\\NotificationPublisher::publish::UserNotification' => $r22,
    'app/Services/OrganizationDataReset/OrganizationDataResetOperationQuery.php::App\\Services\\OrganizationDataReset\\OrganizationDataResetOperationQuery::deleteSalesReservationGraph::InventoryBalance' => $r4,
    'app/Services/OrganizationDataReset/Selective/SelectiveResetExecutor.php::App\\Services\\OrganizationDataReset\\Selective\\SelectiveResetExecutor::release::InventoryBalance' => $r4,
    'app/Services/OrganizationDataReset/Selective/SelectiveResetPlanner.php::App\\Services\\OrganizationDataReset\\Selective\\SelectiveResetPlanner::stateHash::InventoryReservation' => $r4,
    'app/Services/Pos/PosDraftLifecycle.php::App\\Services\\Pos\\PosDraftLifecycle::abandonEmptyCarts::SalesOrder' => $r7,
    'app/Services/Pos/PosDraftLifecycle.php::App\\Services\\Pos\\PosDraftLifecycle::activeReservationIds::SalesOrderInventoryAllocation' => $r4,
    'app/Services/Pos/PosDraftLifecycle.php::App\\Services\\Pos\\PosDraftLifecycle::touch::SalesOrder' => $r2,
    'app/Services/Sandbox/SandboxInventoryInitializer.php::App\\Services\\Sandbox\\SandboxInventoryInitializer::ensure::Sandbox' => $rTraced,
    'app/Services/Sandbox/SandboxStaleSession.php::App\\Services\\Sandbox\\SandboxStaleSession::audit::Sandbox' => $r13,
    'app/Services/Sandbox/SandboxStaleSession.php::App\\Services\\Sandbox\\SandboxStaleSession::forMissingRecord::Sandbox' => $r13,
    'app/Services/Timeline/BusinessTimeline.php::App\\Services\\Timeline\\BusinessTimeline::page::AuditLog' => $r4,
];
