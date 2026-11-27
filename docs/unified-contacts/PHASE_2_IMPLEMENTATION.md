# Unified Contacts — Phase 2 (UI/UX, navigation and frontend consolidation)

Branch `feature/unified-contacts-phase-2-ui`, base `origin/staging` @ `96cd01f` (Phase 1 PR #69 and its CI remediation `9117d8b` are merged there). Backend foundation: `PHASE_1_ADR.md`.

One identity, one contact record, one shared form, multiple roles. No second contact system was built: every write goes through the Phase 1 actions.

## 1. What was delivered

* **Navigation**: a standalone **Contacts** module; *Ventes & Clients* is now **Ventes**; the customer directory left Ventes; the supplier directory left Achats. One registry (`resources/js/layouts/navigation.tsx`).
* **One directory** (`/contacts`) for all views, server-side search, filters and pagination, seven columns.
* **One form** (`ContactForm`) used as a full page, a dialog (POS) and a drawer (quotations), in `quick` or `complete` mode.
* **One canonical detail page** with conditional tabs and role management on the same page.
* **POS** and **quotation** customer creation reuse the shared form; neither leaves the transaction.
* **Settings** page: roles catalogue, payment terms catalogue, read-only migration-conflict review.
* **Duplicate prevention** with graded evidence; nothing is ever merged automatically.
* Archive now also takes the commercial profiles out of the pickers (and restore reinstates exactly what archive switched off).

## 2. Final navigation

```text
Ventes                       POS · Commandes · Devis · Factures · Bons de livraison · Retours · Mes commissions
Contacts                     Tous les contacts · Clients · Fournisseurs · Contacts internes · Contacts externes · Partenaires & prestataires · Paramètres
Achats & Fournisseurs        Commandes fournisseur · Approvisionnement · Factures fournisseurs · Suivi sourcing   (suppliers moved to Contacts)
Finance & Trésorerie         unchanged
```

Every Contacts entry is the same page with a different server-side filter (`/contacts?role=client`, `?role=supplier`, `?role=internal`, `?scope=external`, `?group=partners`). `matchActiveNav(groups, path, search)` is query-aware: an entry with a query string matches only when the URL carries the same parameters and then outranks its plain sibling, so the right entry highlights; detail and creation pages highlight *Tous les contacts* (or *Clients* for `Nouveau client`). `/sales/customers` and `/procurement/suppliers` are **aliases** of their views (active state, breadcrumbs, deep links). Entry permissions: `contacts.view` for all; Clients also accepts `customers.view`, Fournisseurs `suppliers.view` (see §6). Mobile drawer, breadcrumbs and the command palette consume the same registry. Locales: the application ships French and Arabic (RTL); there is no English locale in the code base, so none was added.

## 3. Route map

| Route | Purpose |
|---|---|
| `GET /contacts` | Unified directory. Query: `search, kind, role, roles[], role_ids[], status, scope, group` |
| `GET /contacts/create` | Shared creation page. `?role[]=client|supplier|internal|carrier|…` presets the roles (the server still authorizes each) |
| `POST /contacts/parties` | Create (or reuse via `reuse_party_id`); JSON for dialogs/drawers, redirect for the page |
| `GET /contacts/parties/{party}` | **Canonical detail** (`?tab=client|supplier|relations|activity`). `{party}` is always a Party id |
| `GET /contacts/parties/{party}/edit`, `PATCH …/{party}` | Edit identity (+ desired role set when sent) |
| `POST …/roles`, `DELETE …/roles/{role}` | Add / soft-remove roles (commercial roles reuse or create their profile) |
| `POST …/relationships` | Add an interlocutor (a distinct person linked to the organization) |
| `POST …/archive`, `…/restore` | Protected soft archive / restore |
| `POST …/payment-terms` | Assign a catalogue term to the customer **or** supplier profile independently |
| `GET /contacts/candidates` | Graded duplicate candidates (strong / weak) |
| `GET /contacts/settings`, `POST …/roles`, `POST …/roles/{id}/deactivate`, `POST …/payment-terms` | Settings |
| `GET /contacts/lookup`, `POST/PATCH/DELETE /contacts/{contact}` | **Legacy** OrganizationContact API, unchanged (document e-mail recipient search, MCP, old forms) |
| `GET /contacts/{contact}`, `…/{contact}/edit` | Legacy ids → **redirect** to the contact's own `party_id` page |
| `GET /sales/customers`, `/create`, `/{customer}/edit` | Redirect to the Clients view / creation / canonical edit (users without `contacts.*` keep the legacy pages) |
| `GET /procurement/suppliers` | Redirect to the Fournisseurs view (same fallback) |
| `POST /pos/customers`, `PATCH /pos/customers/{id}`, `POST /quotations/customers` | Transactional contracts, unchanged shape |

### ID compatibility

Party, Customer, Supplier and OrganizationContact ids are different namespaces. `/contacts/parties/{id}` is *only* a Party id. Legacy records are resolved through their **own** `party_id` (`LegacyPartyResolver`); a record created before the unified lifecycle and not yet backfilled gets the party the backfill would give it (never a merge). A numeric coincidence is never used (a test makes the three namespaces disagree on purpose).

## 4. Shared form architecture

```text
components/contacts/
  ContactForm.tsx      one state, one validation, one submit workflow (modes: quick | complete; layouts: page | overlay)
  ContactDrawer.tsx    ContactForm in <Sheet>    (quotations: 24px side padding, sticky footer, RTL aware)
  RoleSelector.tsx     catalogue-driven roles + "Autre"
  PaymentTermSelector  catalogue-driven term select
  DuplicateHints.tsx   debounced /contacts/candidates
  RoleBadges.tsx       role labels (system roles translated by stable key)
  contactValues.ts     pure state / payload / validation / preset logic (unit tested)
  api.ts, types.ts
```

* Wrappers only choose the container; they never hold form state or business logic. Dialog mode is the POS: its existing multi-view dialog (search, create, edit) embeds the form (`layout="overlay"`), so no separate dialog wrapper exists.
* Submit: `layout="page"` uses Inertia (redirect + flash); overlays use JSON and hand a `ContactResult` to `onSuccess`. A `transport` adapter lets a context keep its **existing** endpoint contract (POS, quotations).
* Sections: nature (Personne/Organisation, or **Particulier/Entreprise** in commercial contexts) → essential identity (only the name is required) → duplicate hints → roles (+ **Autre**) → *Plus d'informations* (progressive disclosure: WhatsApp, address, city/country, notes, **ICE / IF / RC** as separate fields) → one clearly identified conditions block per commercial role.
* **Fiscal identifiers are never reinterpreted.** The complete form has ICE, IF and RC fields. The quick commercial forms keep the legacy single *Identifiant fiscal* field mapped to `customers.tax_identifier`, never to an ICE.
* **Autre** reveals a free input persisted by the server as a reusable organization role (or matched to the existing equivalent); creating a *new* role needs `contacts.roles.manage`, reusing one does not. A role grants nothing.
* Roles are the persisted catalogue served by the server (`catalogue.roles`); React holds no second role registry. Client/Fournisseur are hidden when the user cannot assign them.

### Entry points updated

Nouveau contact, Nouveau client, Nouveau fournisseur, Nouveau contact interne (page, presets) · Modifier (page) · Ajouter un interlocuteur (detail page) · POS *Ajouter un nouveau client* and quick edit (dialog) · Devis *Ajouter un client* (drawer) · customer/supplier legacy create/edit URLs (redirects).

## 5. Atomicity, duplicates, lifecycle

* `CreateContactAction` orchestrates the Phase 1 actions in **one transaction** (identity → roles/profiles → terms → interlocutor → legacy contact mirror). A forbidden or failing step rolls back everything, including audit rows (test: a user allowed to create customers but not suppliers asks for both → 403 and *nothing* remains).
* Duplicates: strong = same ICE or exactly the same normalized name; weak = same e-mail or phone (formatting-insensitive). The ICE is matched server side and never echoed. The user can open the candidate, **reuse** it (the form then only adds roles to it) or continue with a genuinely distinct contact.
* An identity-only edit never touches roles; a deactivated commercial role is only reactivated when the user ticks it again (same customer/supplier id). Removing Client/Fournisseur and archiving are refused while money is open (`PartyLifecycleGuard`); the profile is never deleted.
* Archive: party status, linked legacy contacts hidden, and customer `inactive` / supplier `inactive` (remembered in `parties.archive_snapshot`, new nullable column) so pickers stop offering them; restore reinstates exactly that. History stays readable.
* `PartyController`, `CreateContactAction` etc. keep every query organization-scoped (the architecture guards pass with no exception entries).

### Directory semantics

* `roles[]` / `role_ids[]`: holds **at least one** of the listed active roles. `status`: active (default) | archived | all.
* **Internal** = holds the active *Interne* role; **external** = does not. **Partenaires & prestataires** = holds *Partenaire*, *Prestataire* or *Transporteur*.
* Search covers name, legal name, phone, e-mail, WhatsApp, ICE, RC and the fiscal identifier (the directory requires `contacts.view`; a `customers.view`-only user is forced to the Clients view and sees neither other roles nor supplier ids).

## 6. Permissions

Unchanged keys. Contacts entries need `contacts.view`; `customers.view` / `suppliers.view` keep their own directory view. Detail/create/edit/settings need `contacts.*`. Client and Fournisseur activation additionally need `customers.create` / `suppliers.manage`; a payment term attached to a customer needs `finance.customers.terms.manage`, to a supplier `suppliers.manage`; a new custom role `contacts.roles.manage`; catalogue terms `contacts.payment_terms.manage`; archive `contacts.archive`. Customer/supplier ids, fiscal identity and financial links are serialized only for viewers of those modules; **no banking data appears anywhere** in the contact payloads (tested). Presets are UI hints only: the server authorizes every role.

## 7. Legacy pages: consolidated, retained, retired

| Legacy | Outcome |
|---|---|
| `Contacts/Index`, `Contacts/Form`, `Contacts/Show` | **Replaced** by the unified pages |
| `/sales/customers` index, create, edit | **Redirect** to the unified views; React pages kept only as the fallback for users without `contacts.*` |
| `/procurement/suppliers` index | **Redirect**; page kept as the same fallback |
| `Sales/Customers/Show`, `Procurement/Suppliers/Show` | **Kept** as the "Fiche commerciale" / "Fiche fournisseur": the Client and Fournisseur tabs link to them (orders, quotes, invoices, payments, delivery notes, follow-ups, supplier catalogue, pricing). Not re-implemented, so no financial read model was duplicated |
| `Pos/PosCustomerDialog` | Search/select UX unchanged; create + edit views now the shared form |
| `Quotations/Create` | link to `/sales/customers/create` replaced by the drawer |

## 8. Backward compatibility

Legacy routes keep their names and behaviour (store/update/destroy/lookup of contacts, customer and supplier store/update/show, POS and quotation customer endpoints with the same payloads and status codes). Existing bookmarks resolve through their own ids. The only legacy behaviour changed on purpose: `PATCH /pos/customers/{id}` no longer **clears** advanced fields (notes, address, fiscal identifier, e-mail, phone) the client did not send (an explicit empty value still clears), as required by the quick-edit rule. `contacts.show` for a legacy id is now a redirect (its legacy test was updated accordingly).

## 9. Known limitations (honest list)

* **Documents** section: the backend has no contact attachment capability and none was built; no upload UI is shown. Needs a scoped storage/permission design.
* **Customer and supplier sheets are not re-hosted** in the canonical tabs; they are linked (see §7). A later phase can extract `SupplierRelationTable`, the order/quote/invoice sections, etc. into tabs.
* **Customer credit settings** (credit limit, deposit, tolerance, policy, invoicing policy) are displayed and linked, but still edited in Finance clients (one backend, no divergent copy). The form edits payment terms only.
* **Custom payment delays** are created in Settings, not inside the form.
* **Conflict review is read-only**; no merge or resolution workflow (deliberately).
* **Relationships** can be added from an organization; there is no edit/remove UI yet. A person can be created as the interlocutor of an organization during its creation.
* Quotation **Show** keeps its own *create customer* entry (conversion flow) and sales-order customer pickers are Phase 3 transaction integration.
* The quick POS/quotation forms do not offer a "use existing contact" action (the hint is informational there); the POS search is the way to select one.
* French and Arabic only (no English locale exists in the application).
* **Browser/UAT**: see the validation report; visual and interactive checks are reported separately from the automated results.

## 10. Remaining Phase 3

Payment-term driven due dates and the deposit/installment engine (the catalogue is descriptive only); invoice and installment due-date resolution; migrating the remaining transactional pickers (orders, procurement, shipments) onto the shared form; extracting the commercial tabs from the legacy sheets; contact documents; assisted conflict resolution/merge; relationship edit/removal.
