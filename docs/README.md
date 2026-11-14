# Approved 10xScale ERP Liquid Glass — Claude Code visual handoff

This package is for implementing the user's **approved generated dashboard image** in their EXISTING Laravel + React ERP.

## Files

- `docs/design/approved-liquid-glass-dashboard.png` — image target (1774×887)
- `docs/design/liquid-glass-dashboard.html` — self-contained HTML + CSS approximation, opens locally in any browser
- `docs/design/VISUAL_SPEC.md` — palette, sizing, interaction rules and acceptance criteria
- `docs/tasks/CLAUDE_CODE_TASK.md` — instructions for Claude Code

## Install

1. Extract the ZIP.
2. Copy the `docs/` folder into your ERP repository root, merging with existing `docs/`. Do not replace the ERP code or `CLAUDE.md`.
3. In the same Claude Code session, tell it: **Read `docs/tasks/CLAUDE_CODE_TASK.md` and execute the task.**

The PNG is authoritative for **visual appearance**. The HTML is a hand-built approximation; the actual ERP source is authoritative for all runtime behavior. Everything in the HTML is illustrative. Zero data must remain zero; fictional trend graphs in generated images must not be implemented as real metrics.
