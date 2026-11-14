# Start here — Claude Code handoff

1. Copy the **contents** of this ZIP into the root of the actual 10xScale ERP repository (merge `docs/` if it exists; review existing `CLAUDE.md` before overwriting).
2. Create a feature branch, e.g. `ui/navigation-dashboard-redesign`.
3. Open `docs/design/erp-layout-reference.html` in a browser to inspect the interactive concept.
4. Run Claude Code at repository root and use:

   Read CLAUDE.md, docs/design/IMPLEMENTATION_SPEC.md, docs/tasks/01-shell-dashboard.md and docs/design/erp-layout-reference.html. Implement Task 01 in the actual ERP React/TypeScript code. Use HTML as a visual and behavior reference, not code to paste. Map existing nav/routes/permissions and real dashboard props first; preserve all business logic. Implement in small reviewable changes, run available targeted checks, and report succinctly. Do not touch other modules or deploy.

Note: another project (`commerce-app`) inspired the navigation only. Do not modify it or copy its business logic.
