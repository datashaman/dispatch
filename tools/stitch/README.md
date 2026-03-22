# Stitch Bridge

Node.js CLI bridge for [Google Stitch SDK](https://github.com/google-labs-code/stitch-sdk) — generates and edits UI screens programmatically. Called by Dispatch agents via the Bash tool.

## Setup

```bash
cd tools/stitch
npm install
```

Requires `STITCH_API_KEY` environment variable (get one from [stitch.withgoogle.com](https://stitch.withgoogle.com)).

## Usage

### Generate a screen

```bash
node stitch-bridge.js generate \
  --project "proj_abc123" \
  --prompt "Login form with email and password fields" \
  --vibe "$(cat DESIGN.md 2>/dev/null)" \
  --device DESKTOP
```

### Edit an existing screen

```bash
node stitch-bridge.js edit \
  --screen "scr_xyz789" \
  --prompt "Add a forgot password link below the form"
```

### Output format

All output is JSON to stdout. Errors go to stderr with exit code 1.

```json
{
  "screen_id": "scr_xyz789",
  "project_id": "proj_abc123",
  "html": "<html>...</html>",
  "screenshot_url": "https://stitch.googleapis.com/..."
}
```

### Options

| Flag | Required | Description |
|------|----------|-------------|
| `--project` | generate | Stitch project ID |
| `--prompt` | both | Design prompt |
| `--vibe` | no | Design system context (e.g. DESIGN.md content) |
| `--device` | no | `DESKTOP` (default), `MOBILE`, `TABLET`, `AGNOSTIC` |
| `--screen` | edit | Screen ID to modify |

## Pipeline

The bridge is used in a two-agent pipeline defined in `dispatch.yml`:

```
GitHub Issue (label: stitch-design)
    │
    ▼
stitch-designer agent
    │  Calls stitch-bridge.js generate
    │  Outputs HTML with markers:
    │    <!-- STITCH_SCREENSHOT: url -->
    │    <!-- STITCH_HTML_START -->
    │    <html>...</html>
    │    <!-- STITCH_HTML_END -->
    │
    ▼
stitch-implementer agent (depends_on: stitch-designer)
    │  Receives designer output via upstream_outputs
    │  Extracts HTML + screenshot via regex
    │  Translates to Blade/Livewire + Tailwind
    │  Creates PR
    ▼
```

## Tests

```bash
node --test stitch-bridge.test.js
```

12 tests: 7 CLI validation + 5 marker format extraction.

## Security

- `STITCH_API_KEY` is read from `process.env` only — never passed via CLI args (would leak into agent_runs.steps)
- The bridge never writes files — JSON-only stdout

## Phases

This is the **Phase 1 spike**. See TODOS.md for Phase 2 (thin slice) scope:
- StitchDesignExecutor (procedural executor)
- StitchGenerateTool + StitchEditTool in ToolRegistry
- BYOK support for STITCH_API_KEY
