# Design System — Dispatch

## Product Context
- **What this is:** Self-hosted webhook server that dispatches AI agents based on configurable rules
- **Who it's for:** Developers who want AI automation for their GitHub repos
- **Space/industry:** Developer tools, CI/CD, AI agent platforms (peers: GitHub Actions, Linear, Buildkite, Railway)
- **Project type:** Web app dashboard — data-dense, operational, watched by engineers

## Aesthetic Direction
- **Direction:** Industrial/Utilitarian
- **Decoration level:** Minimal — typography and spacing do the work. No gradients, no shadows, no flourishes. Functional color only (status indicators, step-type borders).
- **Mood:** Mission control for AI agents. Calm, competent, data-forward. The UI should feel like it was built by engineers for engineers — not marketed at them.
- **Anti-patterns:** No purple gradients, no 3-column icon grids, no hero sections, no "Built for developers" copy. If it looks like every other AI SaaS template, it's wrong.

## Typography
- **Display/Hero:** Instrument Sans 600 — clean, modern, no-nonsense
- **Body:** Instrument Sans 400 — single font family, hierarchy through size/weight only
- **UI/Labels:** Instrument Sans 500
- **Data/Tables:** Geist Mono 400 — true monospace for token counts, costs, durations, diffs. Must support `font-variant-numeric: tabular-nums`.
- **Code:** Geist Mono 400 — same as data, consistent monospace voice
- **Loading:** Bunny Fonts CDN (`fonts.bunny.net`). Instrument Sans already loaded. Add Geist Mono.
- **Scale:**
  - 30px — Page titles
  - 24px — Section headings
  - 20px — Card titles, modal headers
  - 16px — Body text, descriptions
  - 14px — Table cells, form labels, secondary text
  - 12px — Captions, metadata, timestamps, badges
  - 11px — Uppercase section labels, column headers

## Color
- **Approach:** Restrained — one accent + neutrals. Color is rare and meaningful.
- **Primary:** `#6366f1` (indigo-500) — buttons, links, active states, chart bars. Indigo over blue to differentiate from generic dev tools.
- **Primary hover:** `#818cf8` (indigo-400)
- **Neutrals:** Zinc scale (Tailwind `zinc-50` through `zinc-950`)
- **Semantic:**
  - Success: `#22c55e` (green-500) — completed runs, positive trends, tool results
  - Warning: `#f59e0b` (amber-500) — budget alerts, running status, caution states
  - Error: `#ef4444` (red-500) — failed runs, over-budget, destructive actions
  - Info: `#3b82f6` (blue-500) — queued status, tool calls, informational notices
- **Theme modes:** Light, Dark, System — via Flux UI `$flux.appearance` segmented control (already in Settings > Appearance)
- **Dark mode (default):** Zinc-900 page bg, zinc-800 card bg, zinc-700 borders, zinc-100 primary text, zinc-400 secondary text
- **Light mode:** White (`#ffffff`) page bg, zinc-50 card bg, zinc-200 borders, zinc-900 primary text, zinc-600 secondary text
- **System mode:** Follows `prefers-color-scheme` media query
- **Both modes required:** All V2 UI components must render correctly in light and dark. No dark-only components.
- **Semantic colors in dark mode:** Reduce saturation ~10% for visual comfort. Use `color-mix()` or Tailwind opacity modifiers for tinted backgrounds (e.g., `bg-green-500/10` for success alert bg).

## Spacing
- **Base unit:** 4px
- **Density:** Compact — this is a developer tool, not a consumer app. Tight spacing respects screen real estate.
- **Scale:** 2xs(2px) xs(4px) sm(8px) md(16px) lg(24px) xl(32px) 2xl(48px) 3xl(64px)
- **Component padding:** Cards 16-24px, table cells 10-16px, buttons 8-16px, badges 2-8px, modals 24px

## Layout
- **Approach:** Grid-disciplined — strict columns, predictable alignment. No creative-editorial asymmetry.
- **Grid:** 1 col mobile, 2 col tablet, 3-4 col desktop (context-dependent)
- **Max content width:** None (sidebar layout fills viewport)
- **Sidebar:** Flux UI `flux:sidebar` — collapsible on mobile, fixed on desktop. Platform nav group: Dashboard, Projects, Webhook Logs, Cost, Templates.
- **Border radius:**
  - sm: 4px — badges, inline elements
  - md: 6px — buttons, inputs, alerts
  - lg: 8px — cards, modals, tables
  - full: 9999px — avatars, pills

## Motion
- **Approach:** Minimal-functional — only transitions that aid comprehension
- **Page transitions:** Livewire `wire:navigate` (already in place)
- **Modal:** Open/close with Flux defaults
- **Streaming steps:** Append with subtle fade-in (respect `prefers-reduced-motion` — instant append if reduced)
- **Hover states:** 150ms transitions on backgrounds and borders
- **Easing:** enter(ease-out) exit(ease-in) move(ease-in-out)
- **Duration:** micro(50-100ms) short(150-250ms) medium(250-400ms)
- **No:** scroll-driven animation, entrance choreography, loading spinners that spin for drama

## Component Patterns (Flux UI)
These are the existing patterns in the codebase. New V2 features should reuse them:

- **Stat grid:** 4-col (2x2 on mobile) of zinc-800 cards with label/value/trend — used on webhook detail, cost dashboard
- **Status badges:** `flux:badge` with semantic colors — green (completed), amber (running), red (failed), indigo (queued), neutral (skipped/idle)
- **Tables:** `flux:table` with zinc-700 borders, uppercase 11px column headers, hover row highlight
- **Modals:** `flux:modal` centered, 60% width desktop, full-screen on mobile. Tabs via `flux:tabs` for multi-section content.
- **Expand-for-detail:** Chevron toggle per row — used on rules page, reuse for template preview
- **Amber pre block:** `bg-amber-900/20 text-amber-200` for prompt previews — used on rules page, reuse for template prompts
- **Toast notifications:** Flux toast for all success/error confirmations. Conversational tone.
- **Forms:** `flux:input` with labels above. Inline-editable values use click-to-edit pattern.
- **Agent step borders:** Left border by type — blue (`border-blue-500`) for tool calls, green (`border-green-500`) for tool results, neutral (`border-zinc-700`) for text responses.

## Functional Color Rules
Color is never decorative. Every use of color must communicate:
- **Status:** green/amber/red/indigo/neutral for run states
- **Step type:** blue border = tool call, green border = tool result
- **Budget:** green (<50%), amber (50-90%), red (>90%)
- **Trends:** green arrow up (positive), red arrow down (negative), muted dash (flat)
- **Actions:** indigo for primary buttons, neutral for secondary, red outline for destructive
- **Alerts:** Left border + tinted background. Conversational tone, not "WARNING: ..."

## Decisions Log
| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-03-19 | Initial design system created | Created by /design-consultation. Industrial/utilitarian aesthetic for a dev-tool dashboard. |
| 2026-03-19 | Instrument Sans + Geist Mono | Single sans-serif for hierarchy through weight/size. Geist Mono for all technical data — distinctive without sacrificing legibility. |
| 2026-03-19 | Indigo accent over blue | Differentiates from generic dev tools. Pairs better with warm zinc neutrals. |
| 2026-03-19 | Alpine + CSS bars for charts | Zero JS dependency. Styled divs with Alpine tooltips. Matches Livewire-first approach. |
| 2026-03-19 | Server-side diff rendering | PHP generates pre-formatted HTML with Tailwind coloring. No client-side JS library. |
| 2026-03-19 | Light/Dark/System theme | All three modes via Flux `$flux.appearance`. Dark is default but all UI must work in both modes. |
