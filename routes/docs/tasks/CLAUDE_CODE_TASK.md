# Task — implement the approved Liquid Glass Dashboard reference

Continue in the **same** existing Claude Code session and ERP repository. First inspect the files in `docs/design/`. Use the attached approved PNG visually. This is NOT a request to recreate a screenshot as a static page.

## Inputs (mandatory, in priority order)

1. `docs/design/approved-liquid-glass-dashboard.png` — approved desktop target, 1774×887
2. `docs/design/liquid-glass-dashboard.html` — editable CSS/layout reference and responsive concepts
3. `docs/design/VISUAL_SPEC.md` — detailed implementation rules
4. Existing 10xScale ERP source code — sole truth for behavior, access, real metrics and architecture

## Implementation

- Inspect `resources/css/app.css`, `resources/js/layouts/ApplicationShell.tsx`, actual dashboard components, real navigation registry, shared UI components and present theme tokens.
- Keep the existing **four leading KPI cards**, sales/collections panel, alert/task panel, orders panel, quick actions, top products, indicators and setup prompts where they truly exist and are permission-supported.
- Reproduce **the overall final appearance as closely as reasonably possible**: warm paper canvas and subtle sea-glass/sand atmosphere, luminous translucent floating header and rail, editorial dark typography, teal identity, refined card surfaces, generous but efficient layout, elegant empty states, strong numerical hierarchy. Remove any remaining generic, harsh, uniform AI-card styling.
- Use the HTML as a source of initial CSS values and DOM proportions, **not a substitute for existing React code**. Do not copy its fake data/illustrations/labels as production data.
- Refine existing shared tokens and dashboard components. Avoid a new parallel design system. Keep dense business content on opaque readable surfaces.
- Keep header brand at start edge, contextual module navigation centered, utility controls at end edge; floating rail content-height and centered vertically, edge handle at 50dvh, drawer as overlay. RTL mirrors correctly. Keep menu and palette behavior.
- Preserve real empty data displays. The PNG shows decorative sparklines/chips on zero values: DO NOT fabricate metrics or trends. Trends only when supported by real series.
- Ensure visual fidelity across desktop and responsive layouts; support light/dark and reduced transparency/motion.

## Browser validation / visual comparison (not optional)

1. Capture the current real dashboard at 1774×887 (or closest exact screenshot with controlled viewport) BEFORE modifying.
2. Implement the design. Capture real dashboard AFTER at same viewport. Compare with approved PNG side by side for: composition; header/rail transparency; background light; four KPI card geometry; panel grid; typography; spacing; empty states; colors and shadows.
3. Iteratively address the visible discrepancies. When the real data differs from the PNG, judge layout and styling independently.
4. Capture at 390, 768, 1024, 1440, 1920 and 2560 widths. Test dark mode, Arabic RTL, floating drawer, keyboard and global search.
5. Run `npx tsc --noEmit`, `npm run build` and relevant dashboard / navigation tests. Verify no horizontal overflow and no console errors.
6. Report files changed, screenshots, actual visual differences still remaining, and functional QA. Do not claim an exact pixel match unless independently measured.

## Safety / scope

- No changes to backend business logic, database records, organization isolation, permissions, or report calculations.
- Keep fixes from preceding phases including sandbox financial reporting isolation.
- Don't overwrite the current `CLAUDE.md`; this task lives under `docs/tasks/`.
- No commit, push, merge or deploy without permission.

**Start with the approved image and implement the visual refinement in the existing ERP now, then show the real BEFORE/AFTER comparison.**
