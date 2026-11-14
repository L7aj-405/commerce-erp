# 10xScale ERP — UI/UX implementation rules

## Scope and project identity
This repository is the 10xScale ERP (Laravel + React + TypeScript + Tailwind + Inertia). Do not confuse it with `L7aj-405/commerce-app`, a DIFFERENT project cited only as inspiration for navigation behaviors. Do not copy that project's routes, feature list, business logic, or framework conventions.

## Sources of truth, in precedence order
1. This ERP's actual routes, permissions, layouts, domain contracts, and backend data are the functional source of truth.
2. `docs/design/erp-layout-reference.html` is the approved **visual and interaction** reference (not application code to paste in).
3. `docs/design/IMPLEMENTATION_SPEC.md` describes the behavior to port to React.
4. The HTML's fake data, demo route handler, and placeholder UI are NOT sources of business truth.
If reference conflicts with real functionality, preserve real behavior and adapt its presentation.

## Architecture and behavior
- Keep existing app routes, auth, tenant/organization boundaries, permissions, forms, APIs, actions, and validations intact.
- Use `resources/js/layouts/ApplicationShell.tsx` as the starting shell; keep module layouts and POS workflows functional. Dashboard lives in `resources/js/pages/Platform/Index.tsx` (not marketing Home).
- Derive icon rail, full navigation drawer, and contextual header tabs from one permission-aware navigation registry based on existing nav definitions. No duplicated manually maintained route lists.
- Parent module selection displays a default permitted child route; active route determines highlighted child and header group.
- Condense long header navigation into high-priority visible tabs + grouped dropdowns + responsive overflow/More; every permitted child stays reachable by keyboard and mouse.
- The edge hover target must be separate from icon rail hit areas. The rail icons must remain directly clickable. Offer explicit open/close control as an alternative to hover; on touch use click only.
- Use meaningful unique main-module icons and visible labels/tooltips and focus states.
- Light and dark mode must both have accessible contrast; neutral black/white/soft gray palette, restrained glass on structural surfaces, solid/semi-solid backgrounds behind data.
- Quick actions: New Sale -> existing POS, New Purchase -> real procurement/vendor workflow, Add Product -> product workflow, Add Contact -> contact workflow. Show only when permitted.
- Never invent API responses, analytical metrics, data sources, permissions, or routes. Show states for loading, empty, error and forbidden.

## Implementation discipline and token efficiency
- Before each task inspect only directly relevant files and route/permission definitions; do not scan the whole repo or paste large file contents into chat.
- Work in small vertical slices; reuse existing components, CSS tokens and patterns. Avoid unrelated refactors, dependencies, and wholesale rewrites.
- Keep change sets reviewable. Never force-push or deploy without explicit instruction.
- After each slice run the available targeted TS/build/tests; report the command and result accurately. Do not claim browser checks unless performed.
- Report in <= 12 lines: files changed, user-visible behavior, test results, remaining blockers.
- Do not overfit the HTML mock to pixel-perfect at the cost of responsiveness or ERP correctness.

## Deliverable sequence
1. Task 01 `docs/tasks/01-shell-dashboard.md`: shell/navigation, themes, dashboard based on real props.
2. Shared component styling: buttons, inputs, selects, dialogs, badges, tables, empty/loading states.
3. Module by module: POS, Catalog, Sales, Procurement, Inventory, Finance, Settings; preserve workflows.
Do NOT attempt to restyle the whole application in one untested change.
