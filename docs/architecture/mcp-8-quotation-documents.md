# MCP-8 Quotation documents, email and revisions

| tool | MCP scope (= ERP permission) |
|---|---|
| `get_quotation_document` | `quotations.view` |
| `preview_quotation_email`, `send_quotation_email` | `quotations.email` |
| `preview_quotation_revision`, `create_quotation_revision` | `quotations.issue` (what `StartQuotationRevisionAction` requires) |

No `quotations.send`, `quotations.revise`, `quotations.pdf` or stamp scope:
the ERP already names these capabilities `quotations.email` / `quotations.issue`.

## PDF delivery

`get_quotation_document` renders with `DocumentPdfService::quotation` (the same
PDF as the web preview; drafts keep the BROUILLON watermark) to validate it and
report filename / size / sha256, then returns a `download_url`: a Laravel
temporary signed route (`mcp.documents.quotation`, default 600 s,
`MCP_DOCUMENT_LINK_TTL_SECONDS`) bound to the quotation id and MCP connection.
Opening it re-checks the connection (revocation, expiry, organization policy,
membership), the `quotations.view` scope + ERP permission and store access, then
streams the PDF `inline`, `no-store`. No filesystem path, no permanent URL, no
base64 PDF in the tool result.

## Company stamp

The ERP renders a stamp only from a persisted apposition created by the
explicit `StampQuotationAction` (permission `documents.stamp`, issued current
quotations only). There is no render-time toggle, so `include_company_stamp`
never applies or removes a stamp: `company_stamp_applied` reports reality, with a
`stamp_not_apposed` / `stamp_already_apposed` warning when it differs from the
request. Applying a stamp through MCP is not part of this phase.

## Email

Preview reuses the send path's own pieces: status rule (issued, not superseded),
`OrganizationOutboundMailService::isConfigured`, `DocumentEmailRecipientService`
envelope validation (no contact is ever saved), the default subject translation,
`DocumentPdfService` for the attachment and `QuotationDocumentMail` rendered for a
text body preview. The fingerprint (300 s, single use) fixes organization,
connection, user, quotation id + version, recipient, subject, message and stamp
state. Send claims the fingerprint atomically (`Cache::add`), re-checks the
quotation version, and calls `SendQuotationEmailAction` with exactly those
values. Sending is synchronous (SMTP accepted = `status: sent`; not a delivery
receipt; `queued: false`). The ERP writes its own `DocumentEmailDelivery`
(sent/failed) and `quotation.sent` / `quotation.email_failed` audit.

Idempotency uses `McpIdempotencyService::executeExternal`: not wrapped in a DB
transaction (so the ERP's failure records survive), and the outcome — sent or
failed — is stored, so the same key never sends twice; a failure must be
previewed again with a new key.

## Revisions

`StartQuotationRevisionAction` creates a new draft linked by
`root_quotation_id` / `revised_from_quotation_id` / `revision_number`, copying
the issued quotation's snapshots and lines verbatim; the source stays immutable
until the revision is issued (`IssueQuotationAction` then supersedes it). The
preview runs it in a rolled-back transaction; the fingerprint binds the source
state, the reason and the hash of the resulting draft; creation re-runs it and
rolls back on any difference. Edit the revision with `preview_quotation` /
`update_quotation_draft`, issue it with `issue_quotation`, send it with the email
tools.
