# Task 01 — Application Shell + Navigation + Dashboard

Goal: implement the approved HTML behavior and appearance in the REAL ERP, not a demo SPA.

## Phase A: inspect and map (short report)
- Read `resources/js/layouts/ApplicationShell.tsx`, `resources/js/pages/Platform/Index.tsx`, `resources/css/app.css`, and directly relevant route/permission helpers.
- Enumerate parent modules, authorized child pages, active-route rules, store/org dependencies, and actual Dashboard props.
- Identify reusable UI components and existing theme behavior.

## Phase B: implement
- Build reusable navigation registry using the existing real menu config as source of truth.
- Implement compact icon rail, independently triggered full drawer, contextual topbar tabs, grouped dropdowns and responsive More overflow.
- Style shell using the HTML reference (neutral/light, subtle glass, solid readable data surfaces). Add functional light/dark switch, including accessible contrast.
- Refresh Dashboard presentation using existing real data. If trend/chart endpoints do not exist, do NOT fake them; use existing operational widgets.
- Wire Quick Actions to existing routes and permissions. Keep the current feature and route guards.

## Phase C: verify
- `npm run build` / existing TS/test commands if dependencies available.
- Test active tabs on deep links and navigation history; verify no overflow; edge hover strip vs rail; permission conditions; mobile drawer; keyboard accessibility.
- Do not modify POS transaction logic yet. Leave it for a separate task.

Acceptance: no missing links or roles, no invented business metrics, no header overflow, no hover interference, readable light/dark, clean build.
Keep final report concise. No deployment/merge without user approval.
