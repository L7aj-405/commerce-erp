# 10xScale ERP — Approved Dashboard Visual Specification

## Precedence

1. **`approved-liquid-glass-dashboard.png` (1774 × 887)** is the **approved visual target** for desktop appearance, composition, typography hierarchy, color atmosphere, translucent surfaces, proportion and spacing.
2. **`liquid-glass-dashboard.html`** is an **editable approximation** of those shapes, tokens and responsive ideas, NOT a pixel-accurate conversion. Its copy and illustrated metrics are NOT implementation requirements.
3. **The actual Laravel/React ERP** remains authoritative for all data, navigation, permissions, business logic, translation, actions, empty states, API integrations and accessibility.

Where the HTML and screenshot differ, follow the image for visuals. Where the image differs from real ERP data and behavior, retain correct ERP logic.

## Art direction

- Business-grade, editorial, **warm near-white** dashboard with **visible but restrained liquid glass**.
- No purple. Palette: deep teal / sea glass, muted amber/orange for warnings, very faint blue for informational statuses; black/dark graphite typography.
- Visual hierarchy from contrast, scale and meaningful surface layering, *not* uniform card grids, gratuitous glows or purple gradients.
- Blurred translucent **top header and floating left rail**, selected elevated controls and overlays. Data cards remain *near-white and legible*; cards have soft optical highlights and nuanced outlines.
- Subtle ambient background depth in upper right (sea-glass tint) and lower left (warm sand). No conspicuous orb, heavy grid, neon halo or noisy visual texture.
- Treat the screenshot as a **visual composition**, not a source of business metrics. The generated image depicts 0% change and sparkline decoration with zero values; **DO NOT add fabricated trends or charts**. Render change chips and sparklines only if actual backend comparison/series exists.

## Style tokens — suggested initial CSS values; tune visually against PNG

| Token | Value | Usage |
|---|---|---|
| Canvas | `#F7F5F1` | warm parchment neutral |
| Primary text | `#121B1C` | near black |
| Secondary text | `#606869` | subtitles |
| Main accent | `#007C73` | petrol teal |
| Accent strong | `#005D56` | active states / contrast |
| Soft teal | `#DCEFE9` | tint / chip |
| Attention amber | `#CA852A` | real warnings only |
| Main data surface | `rgba(255,254,252,.94)` | near-solid cards |
| Elevated glass | `rgba(255,255,255,.73)` | navigation chrome |
| Glass edge | `rgba(255,255,255,.84)` | subtle lit top line |
| Hairline | `rgba(87,102,101,.14)` | borders |
| Card radius | `16–18px` | not oversized rounded pills |
| Control radius | `10–14px` | buttons, filter inputs |
| Floating rail radius | `24–26px` | portrait floating pill |
| Main card shadow | `0 12px 26px rgba(35,53,49,.075), 0 2px 6px rgba(35,53,49,.025)` | neutral soft depth |
| Glass blur | `16–24px` | header, rail, popovers |

Use existing installed Plus Jakarta Sans for Latin and IBM Plex Sans Arabic for Arabic (actual CSS / font pipeline takes precedence). Preserve WCAG AA text contrast. Dark theme needs its own graphite-neutral palette and translucent glass treatment; don't invert a screenshot blindly.

## Desktop layout at approximately 1774 × 887

- Top bar: floating full-width glass strip inset ~14px horizontally, ~48px high; logo/wordmark left, contextual module navigation centrally aligned, search/store/create/profile on right. Do not crowd or overlap.
- Left icon rail: ~56–58px wide, floating ~20px from the physical edge, **height determined by actual visible icons** and vertically centered. Independent **edge handle centered at exactly 50dvh**. Preserve LTR/RTL mirroring and hover vs click semantics.
- Main workspace begins at ~118px left on desktop after rail; right outer margin ~28px. **No restrictive max width.**
- Title section: left aligned, page name around 30px with confident black type; period selector on the right.
- First row: **FOUR prominent KPI cards** (sales net, orders, receivables, collections net according to existing role/permission logic), balanced 4-column grid; ~128px high in image. Keep labels, icon tiles, values and comparisons legible.
- Second and third row: three-column dashboard grid, **roughly 1.7 : 1 : 1** (main sales/collections chart, alerts & tasks, order state; then quick actions, top products, secondary indicators). Image is an aspirational layout, so preserve existing role-aware content/priority and reflow sensibly.
- Lower row: setup and next-action cards in two columns when those are actually relevant.
- Empty states are intentional: quiet slightly contrasting inset panel, modest pictogram where warranted, one concise primary message plus secondary explanation. Do not leave enormous sterile blank rectangles.
- For 1920 and 2560, **keep fluid overall workspace**, but control line length, use proportional grids, and avoid giant near-empty KPI cards. Expand useful panels, not padding.
- Tablet/mobile: 4→2 KPI cards then 1 if truly necessary; stack other grids logically; preserve actions and no page horizontal overflow. Use rail safe height and mobile drawer behavior.

## Specific finish detail

- Header text positions and actual navigation affordances must match the existing architecture.
- KPI label and value hierarchy stronger than current screenshot, not huge color blocks. Currency suffix smaller than number.
- Use **one recognizable glass language**: translucent material + fine light edge + backdrop blur + believable shadow + backdrop contrast. Generic `bg-white/80` everywhere is NOT sufficient.
- Accent should not color every icon and heading. Distinguish meaningful KPI types with muted tinted icon tiles.
- Faint background atmosphere must remain visible outside and between near-white cards; no obvious purple/violet field.
- Apply to existing shared CSS / React components, not a separate demo HTML port.

## Constraints

- Keep existing production data and permissions. The image is a generated *design visual*, not an app state.
- Don't recreate static numbers, fake activity feed, fictional change percentages, inactive buttons, hardcoded page links, sample orders, or fake customer data.
- Existing admin/seller role dashboards may differ in KPI content; style their actual priority and availability.
- Preserve Test/Production reporting isolation, POS guest sales, navigation keyboard behavior, multilingual RTL, filters, custom dropdowns, centered global command palette, and contextual navigation.
- Fonts must load via the existing Laravel/Vite font pipeline; don't import external paid assets or fonts from a screenshot.
- Only alter UX/visual styles; get explicit authorization for backend behavior changes.
- Keep source assets in repo under `docs/design/` for future comparison.
