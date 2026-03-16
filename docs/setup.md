# Setup Guide

This guide covers installing Dispatch, configuring it, connecting a GitHub App, and creating your first rule.

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| PHP 8.2+ | With `sqlite3`, `pdo_sqlite`, `redis`, `pcntl` extensions |
| Composer | Latest stable |
| Node.js 20+ | For front-end assets |
| Redis | For queuing agent jobs |
| Git | Required for worktree isolation |
| `gh` CLI | [Install](https://cli.github.com/) and authenticate with `gh auth login` |

## Installation

### Option A — one command (recommended)

```bash
git clone https://github.com/your-org/dispatch.git
cd dispatch
composer run setup
```

The `setup` script runs: `composer install`, copies `.env.example` to `.env`, generates an app key, runs migrations, `npm install`, and `npm run build`.

### Option B — manual steps

```bash
git clone https://github.com/your-org/dispatch.git
cd dispatch

composer install

cp .env.example .env
php artisan key:generate

# SQLite (default)
touch database/database.sqlite
php artisan migrate

npm install
npm run build
```

## Environment Configuration

Edit `.env` and set the values relevant to your setup.

### Webhook secret

```env
# Generate with: openssl rand -hex 32
GITHUB_WEBHOOK_SECRET=your-secret-here
VERIFY_WEBHOOK_SIGNATURE=true
```

Use the same secret value when configuring the webhook on GitHub.

### Bot username

```env
# Events from this GitHub user are ignored to prevent agent feedback loops
GITHUB_BOT_USERNAME=your-bot-username
```

### AI provider keys

Set at least one:

```env
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...
```

The key name must match the `secrets.api_key` value in your `dispatch.yml` or rule config.

### Redis

The default config assumes a local Redis on port 6379. Adjust if needed:

```env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

## Starting the Development Server

```bash
composer run dev
```

This starts four processes concurrently:

- `php artisan serve` — HTTP server on port 8000
- `php artisan queue:listen` — processes agent jobs
- `php artisan pail` — live log tail
- `npm run dev` — Vite HMR

Open `http://localhost:8000` and register the first account.

## GitHub App Setup

A GitHub App gives Dispatch an installation token for each repository, enabling it to post comments, create PRs, and set up webhooks automatically.

### Option A — UI-driven manifest flow (recommended)

1. Go to **Settings → GitHub App** in the Dispatch UI.
2. Click **Create GitHub App**.
3. You'll be redirected to GitHub to confirm the app name and permissions.
4. After confirming, GitHub redirects back to Dispatch and credentials are stored automatically.
5. Click **Install** to install the app on the repositories you want to monitor.

This flow writes `GITHUB_APP_ID` and `GITHUB_APP_PRIVATE_KEY` directly to your `.env` file — the file must be writable by the web process.

### Option B — manual

1. Go to **GitHub → Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Set the **Webhook URL** to `https://your-dispatch-host/api/webhook`.
3. Set the **Webhook secret** to the value of `GITHUB_WEBHOOK_SECRET` in your `.env`.
4. Grant the following **Repository permissions**:
   - Contents: Read & write
   - Issues: Read & write
   - Pull requests: Read & write
   - Metadata: Read
5. Subscribe to events: Issues, Issue comment, Pull request, Pull request review comment.
6. Create the app, then generate a **Private key** (downloads a `.pem` file).
7. Add to `.env`:

```env
GITHUB_APP_ID=123456

# Option 1: base64-encoded PEM (preferred for deployment)
# base64 < your-app.private-key.pem | tr -d '\n'
GITHUB_APP_PRIVATE_KEY=LS0tLS1CRUdJTi...

# Option 2: path to PEM file (convenient for local dev)
GITHUB_APP_PRIVATE_KEY_PATH=/path/to/your-app.private-key.pem
```

## Creating Your First Project

1. Open `http://localhost:8000` and log in.
2. Go to **Projects → New Project**.
3. Enter a name and the **absolute path** to the local clone of the repository on the Dispatch server (e.g. `/home/user/repos/my-project`).
4. Save the project.

## Creating Your First Rule

### Via the UI

1. Open the project and go to **Rules → New Rule**.
2. Choose the **Event** (e.g. `issues.opened`).
3. Write a **Prompt** using `{{ event.* }}` variables (e.g. `{{ event.issue.title }}`).
4. Add **Filters** to narrow which events trigger the rule.
5. Configure **Agent** settings: executor, provider, model, tools.
6. Configure **Output**: log to Dispatch, post a GitHub comment, add a reaction.
7. Save.

### Via dispatch.yml

Drop a `dispatch.yml` at the root of your repository (see the [example](../dispatch.yml) in this repo) and use **Projects → Import from file** to sync it into the database.

You can also export the current database rules back to `dispatch.yml` via **Projects → Export to file**.

## Configuring Webhooks Manually

If you are not using a GitHub App, add a webhook directly to the repository or organisation:

1. Go to **Repository → Settings → Webhooks → Add webhook**.
2. **Payload URL**: `https://your-dispatch-host/api/webhook`
3. **Content type**: `application/json`
4. **Secret**: value of `GITHUB_WEBHOOK_SECRET`
5. **Events**: choose individual events or "Send me everything".

## Production Deployment

- Use **PostgreSQL or MySQL** in production (`DB_CONNECTION`, `DB_HOST`, etc.).
- Use a proper queue worker: `php artisan horizon` (monitored at `/horizon`).
- Ensure the `.env` file is writable if using the GitHub App manifest flow.
- Set `APP_ENV=production` and `APP_DEBUG=false`.
- Run `php artisan config:cache && php artisan route:cache` after deploying.
- Schedule `php artisan horizon:snapshot` via the scheduler for queue metrics.

## Prompt Variables

Prompts are rendered with [Blade-style dot notation](https://laravel.com/docs/blade) over the full GitHub webhook payload:

| Variable | Example value |
|----------|---------------|
| `{{ event.issue.number }}` | `42` |
| `{{ event.issue.title }}` | `Bug: thing is broken` |
| `{{ event.issue.body }}` | _(issue description)_ |
| `{{ event.comment.body }}` | `@dispatch implement` |
| `{{ event.comment.user.login }}` | `datashaman` |
| `{{ event.pull_request.number }}` | `7` |
| `{{ event.label.name }}` | `dispatch` |
| `{{ event.sender.login }}` | `datashaman` |

The full payload structure mirrors the [GitHub Webhooks documentation](https://docs.github.com/en/webhooks/webhook-events-and-payloads).
