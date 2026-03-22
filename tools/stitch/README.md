# Stitch Bridge

Node.js CLI bridge for [Google Stitch SDK](https://github.com/google-labs-code/stitch-sdk) — generates and edits UI screens programmatically. Called by Dispatch's `StitchBridge` PHP service via `Process::run()`.

## Setup

```bash
cd tools/stitch
npm install
```

## API Key Management (BYOK)

Stitch API keys follow the same BYOK (Bring Your Own Key) pattern as AI provider keys:

1. **User-level**: Settings → API Keys → Stitch. This is the primary place to configure your key.
2. **Project-level override**: Projects → Edit → Stitch API Key. Overrides the user-level key for this project only.

The resolution cascade is: project `agent_secrets['stitch']` → user `api_keys['stitch']` → error.

The `.env` `STITCH_API_KEY` is for local development/testing of the bridge CLI only — it is **not** used by the production key resolution.

## Stitch Project Lifecycle

Each Dispatch Project gets its own Stitch project. The `stitch_project_id` is stored on the `projects` table and created automatically on first use:

1. Agent rule fires with `StitchGenerate` tool
2. `StitchBridge` checks `Project.stitch_project_id`
3. If null → calls `create-project` to create a new Stitch project (title = `owner/repo`)
4. Stores the returned ID on the Project model
5. Subsequent runs reuse the cached ID

No manual Stitch project setup is needed.

## CLI Usage

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

### Create a project

```bash
node stitch-bridge.js create-project --title "owner/repo"
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
| `--prompt` | generate, edit | Design prompt |
| `--vibe` | no | Design system context (e.g. DESIGN.md content) |
| `--device` | no | `DESKTOP` (default), `MOBILE`, `TABLET`, `AGNOSTIC` |
| `--screen` | edit | Screen ID to modify |
| `--title` | create-project | Title for the new Stitch project |

## Agent Tools

Agents use Stitch via registered tools (no raw Bash calls needed):

- **`StitchGenerate`** — Generate a UI screen. Accepts `prompt`, `device`, `vibe`. Project ID and API key resolved automatically.
- **`StitchEdit`** — Edit an existing screen. Accepts `screen_id`, `prompt`.

These tools are registered in `ToolRegistry` and available when included in a rule's `tools` list.

## Pipeline

The bridge is used in a two-agent pipeline defined in `dispatch.yml`:

```
GitHub Issue (label: stitch-design)
    │
    ▼
stitch-designer agent
    │  Uses StitchGenerate tool
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
    │  Creates PR (has StitchEdit for iteration)
    ▼
```

## Tests

```bash
node --test stitch-bridge.test.js
```

## Security

- `STITCH_API_KEY` is injected via `process.env` by the PHP service — never passed via CLI args (would leak into agent_runs.steps)
- The bridge never writes files — JSON-only stdout
