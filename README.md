# Central Update Management

Private platform for managing every website running the ecommerce software: clients, their independently hosted installations, signed releases (core, modules, client extensions), and deployments with live status reported by each site.

Separate application with its own database and login. It never connects to client hosting or databases: each site's built-in update agent calls this app over HTTPS.

**Stack:** Laravel 12 · Inertia 2 · React 19 · Tailwind 4 · MySQL (same as the ecommerce app).

## Features

- **Dashboard**: clients, active/offline installations, versions in use, sites behind the latest core, successful/failed updates, recent activity.
- **Clients**: business and hosting details, installations, update history, client extensions; suspend/reactivate.
- **Installations**: unique ID and credentials per website, one-time connection codes, version/modules/PHP/server facts, last check-in, deployment history and activity; suspend, revoke, re-key. Filter by client, status, version and module.
- **Releases**: upload or import a package; type/slug/version read from it; notes, PHP/core/module requirements, upgrade range, migration rollback flag; Ed25519-signed manifest with every file's checksum; publish/withdraw; history.
- **Deployments**: all / filtered / selected / one site; confirmation screen with compatibility results; immediate or scheduled; test site first; automatic retries; cancel pending; per-site rollback; live monitoring and logs. Success only when the site reports it.

## Install (like WordPress)

1. Download `central-vX.Y.Z.zip` from this repository's GitHub release (built by `.github/workflows/release.yml`).
2. Upload it to your hosting (e.g. `public_html` of `updates.yourcompany.com`) and extract it.
3. Create a MySQL database + user (cPanel → MySQL Databases).
4. Open the address. The setup wizard checks the server, tests the database, creates the tables, the **package signing key** and your login, then locks itself.
5. Add the cron job it shows, and download a backup of the signing key it tells you about.

## Releasing an update (the everyday flow)

1. Ecommerce repo: bump `VERSION`, commit, `git tag v1.2.0 && git push origin v1.2.0`.
2. GitHub builds the package. Central imports it (Releases → **From GitHub**, one click, or automatically every hour).
3. Publish it (or let auto-publish do it), then **Deploy** to all, some or one site.

## Updating Central Management itself (one click)

Straight from this GitHub repository, no release zips:

1. Push your changes to `main` (with `public/build` built and committed, as usual).
2. In Central: **Updates** (menu) shows the new commits → **Update now**. It fetches the branch with Git, backs up the database, updates the files (back to the previous commit on any error), runs `composer install` only when `composer.json`/`composer.lock` changed, then the migrations and cache clearing. No terminal needed.
3. Once: set the repository, branch and (for a private repository) a read-only access token under **Updates → Update source**, or `CENTRAL_UPDATE_REPO` / `CENTRAL_UPDATE_BRANCH` / `CENTRAL_UPDATE_TOKEN` in `.env`. Installed from the zip? Press **Connect to GitHub** once (no file changes). **Previous versions** gives one-click rollback to the commit before each update (migrations undone) and its database backup.

Needs Git and `proc_open` on the server (cPanel has both; the page checks). Composer is found automatically or downloaded (checksum verified). `.env`, `storage/` and `vendor/` aren't in Git, so updates never touch them.

## Quick start (local development)

```bash
composer install && npm install --include=dev && npm run build
cp .env.example .env && php artisan key:generate     # set DB_* (MySQL database: central_management)
php artisan migrate
php artisan central:keys
php artisan central:admin you@example.com
php artisan serve --port=8100
```

## Documentation

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): components, layers, deployment flow, security model, limits
- [docs/OPERATIONS.md](docs/OPERATIONS.md): install, connect sites, create releases, deploy, roll back, troubleshoot
- [docs/API.md](docs/API.md): the installation API contract and manifest format
- [docs/CUSTOMIZATIONS.md](docs/CUSTOMIZATIONS.md): keeping client customisations safe; separation strategy
- [docs/examples/](docs/examples/): an example module (`gift-wrap`) and client extension (`acme-pay`)

## Commands

| Command | |
|---|---|
| `central:keys` | Create the package signing key (once); shows the fingerprint |
| `central:admin {email}` | Create/update an administrator |
| `central:import-release {zip} [--client=] [--notes=] [--core=] [--php=] [--from-min=] [--from-max=] [--db-rollback] [--publish]` | Add a release from a file |
| `central:tick` | Scheduled every minute: start scheduled deployments, release retries, time out silent sites |
| `central:github-sync` | Scheduled hourly: import new GitHub releases from repositories set to auto-import |

## Tests

`php artisan test`: API authentication, signatures, replay/clock checks, cross-installation isolation, HTTPS enforcement, rate limits, compatibility, canary, retries, scheduling, cancellation, withdrawal, no-response, rollback, admin pages.
