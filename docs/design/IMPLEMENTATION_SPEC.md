# Design and Interaction Specification — 10xScale ERP

## Visual identity
- Approved reference: `erp-layout-reference.html` (open it locally in a browser).
- Neutral monochrome, restrained liquid/frosted glass. Main background soft gray; primary content panels near-white; dark text. Avoid large saturated green/cyan gradients, neon glow, low-contrast frosted text.
- Primary actions black/charcoal, semantic color only for true operational status; dark theme uses separately tuned contrast tokens.
- Use rounded panels, soft borders, subtle shadows; navigation/layout surfaces can use backdrop blur. Tables and forms must remain easy to read.
- Treat numeric values and business data as first-class. Minimum comfortable text size and accessible target sizes; respect reduced-motion preferences.

## Desktop navigation
1. Floating left **icon rail**: permanent shortcuts per actual parent module. Icons unique and meaningful, accessible names/tooltips; active indication visible.
2. Independent **edge hover strip** at extreme left: only this strip opens the detailed drawer by hover. Rail hover NEVER opens drawer. Full drawer can also be opened by an explicit launcher, closed by Escape/backdrop/close button. Avoid flicker and unwanted route changes.
3. **Full navigation drawer**: searchable, collapsible hierarchical groups. Show only permitted children, keep actual module organization and routes; highlight current location.
4. **Contextual topbar navigation**: after selecting a parent, show only that parent's children; direct main tabs + intuitive subgroup dropdowns + a responsive More overflow menu. All authorized children must remain accessible even on small screens; do not scroll a giant horizontal list or wrap navigation across rows. Active destination should be clear even when inside dropdown.
5. Parent module click navigates to first permitted child unless real ERP routing calls for a module overview; adjust on inspection. Deep links and browser back/forward keep header/drawer state consistent.
6. Keep global search, organization/store context, create controls, notification menu and theme toggle as existing permissions/data allow.

## Responsive behavior
- Large desktop: icon rail + contextual header tabs + edge-trigger drawer.
- Narrow desktop/tablet: reduce direct tabs, move excess to More, preserve search/accessibility.
- Mobile: drawer opened explicitly by menu button; no hover dependency. Actions remain discoverable.

## Dashboard
- Real data only from `resources/js/pages/Platform/Index.tsx` and existing backend props/endpoints.
- Prototype sample KPI numbers, chart curves, table rows, and user identities are demo-only. No fabricated charts, trends or metrics in production. If no supporting data exists, use an existing useful operational block or an honest empty state.
- Keep POS/new sale, procurement/new purchase, product and contact actions connected to their actual permitted flows.

## Interactions to verify
- Click icon -> appropriate module; contextual tabs update.
- Select dropdown item -> correct route; active highlighting follows deep link/back/forward.
- Header never overflows or overlaps topbar controls at widths 1440, 1024, 768, 390.
- Hover edge opens drawer but hovering rail icons does not.
- Drawer keyboard support, Escape, click-away, click/touch open control.
- Permission changes hide unauthorized entries; no unauthorized route becomes accessible via navigation.
- Light/dark contrast, focus rings, reduced motion, screen-reader semantics.
