# 10xScale ERP — Approved Visual Direction V3

## Authority and boundaries
- `approved-dashboard-v3.png` is the user's approved **visual target** for desktop dashboard and navigation styling. Aim for a close visual match in structure, composition, colors, typography, spacing, rounded surfaces, shadows, and accent hierarchy; don't copy image text/data literally.
- Existing ERP source is the sole authority for real routes, models, data, permissions, workflows, translations and feature behavior.
- The previous `erp-layout-reference.html` is a **behavioral/navigation prototype** and a secondary structural reference. Do not let its visual styling override V3.
- The separate `commerce-app` repository was only a navigation-behavior reference, never the target codebase.

## Visual design
- Soft neutral white/lilac background, restrained violet/indigo/blue accents, occasional mint for semantic success, pale glass/frosted containers and clean mostly opaque data cards.
- Avoid large saturated background fields, glare or translucent text areas. Use legible dark text with accessible WCAG AA contrast in light/dark modes. Font must support French and Arabic/RTL and be readable in dense tables.
- Cards with gentle depth, 16–24px radii where appropriate, subtle borders/shadows, discrete motion, never distracting.
- Dashboard hierarchy: priority tasks and relevant KPI cards first, graphs and activity after, based on **actual** role permissions and backend values. Example mockup numbers and events are illustrative, not real data.
- Keep sidebar navigation groups, context-aware header tabs, More overflow, accessible dropdowns, keyboard interaction, and left/right RTL behavior already implemented in Phase 1.

## Crucial nav rail and drawer geometry
- Icon rail must be a compact FLOATING pill with **height determined by its contents** (`height: max-content` / `fit-content`), not `100vh` or `h-full`.
- Vertically center rail in viewport on desktop (`position: fixed; top: 50%; transform: translateY(-50%)`); do not stretch it to the full height.
- A subtle, visibly discoverable **drawer reveal handle/notch** sits on the TRUE left screen edge, **vertically centered at 50vh** (`position: fixed; left: 0; top: 50%; transform: translateY(-50%)`). The notch/handle should be distinct from the rail and may gently pulse only when appropriate; animation must stop for `prefers-reduced-motion`.
- The left-edge hover target is independent of icon hover. Hovering icons must NEVER open the drawer. Pointer entering the edge target reveals the drawer with a small delay; leaving closes with an anti-flicker delay. Also supply obvious click/tap and keyboard alternatives, reliable focus management and Escape close.
- Opening drawer must NOT push or reflow primary workspace: overlay/floating drawer only, with predictable focus and layering. In RTL mirror placement and behavior.
- Visible handle should be easy to discover without obstructing contents, especially on narrow screens.

## Navigation and content
- Preserve real ERP module grouping, menus, permissions, destinations, and translations. The compact rail is curated to avoid too many icons; full drawer exposes all authorized destinations.
- On choosing module, top header shows its authorized children via priority tabs and grouped dropdowns; show More overflow rather than horizontal page scroll. All items remain reachable.
- Quick actions route to existing workflow, not invented screens; POS maintains its specialist workflow and Phase 3 usability improvements.
- Preserve Phase 1–3 completed work and tests. This is a **targeted visual polish** pass, not another architectural rewrite.

## Verification acceptance criteria
- Browser compare at 1440 and 1024 against approved image, plus 768 and 390. Capture *fresh* after-fix screenshots in light and dark.
- Confirm rail height wraps content, rail at screen vertical center, edge handle at exact 50% viewport height, drawer triggered only at edge/button, no flicker, accessible keyboard and RTL mirror.
- Confirm no horizontal overflow, no menu clipping, all permissions/routes intact; search, responsive header, POS still work.
- Run `npx tsc --noEmit`, `npm run build`, and relevant existing tests; report visual differences honestly. Do not commit, push, merge or deploy.
