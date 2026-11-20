# Operating the update platform

## 1. Install the central app (once)

Any PHP 8.2+ host with MySQL (a small VPS or a cPanel account). Needs the `sodium`, `zip`, `pdo_mysql` extensions.

**Setup wizard (no terminal):** upload `central-vX.Y.Z.zip` from the GitHub release, extract it (straight into `public_html` is fine), create a MySQL database + user, open the address and follow the wizard. It creates the tables, the signing key and your login, shows the cron command, and locks itself. Then turn on HTTPS (AutoSSL).

**Or by terminal:**

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# .env: APP_URL=https://updates.yourcompany.com, DB_* for its own database, APP_ENV=production, APP_DEBUG=false
php artisan migrate --force
php artisan central:keys                        # creates the package signing key, shows its fingerprint
php artisan central:admin you@yourcompany.com   # your login (asks for a password, min 12 chars)
npm install --include=dev && npm run build      # or upload a public/build built elsewhere
```

- **Back up `storage/app/private/signing/ed25519.key`** (and `.env`). Losing the key means every site must reconnect.
- Point the domain at `public/` and enable HTTPS (AutoSSL on cPanel). The API refuses plain HTTP.
- Cron, every minute: `* * * * * php /path/to/central-management/artisan schedule:run >> /dev/null 2>&1`
  (starts scheduled deployments, releases retries, marks silent sites "no response").
- For package uploads from the browser, raise `upload_max_filesize` / `post_max_size` (e.g. 300M). Or use `php artisan central:import-release` (no limit).

## 2. Register a client and connect their website

1. Central → **Clients → Register client** (business, contact, hosting details).
2. On the client → **Add installation** (name, domain, environment; tick "test installation" for a staging/test site). You get the **update server address** and a **one-time connection code** (valid 7 days, shown once).
3. On the website: **Admin → Updates → Update server**, enter the address and code → **Connect**.
   (Or on the server: `php artisan agent:connect https://updates.yourcompany.com CODE`.)
4. Compare the **signing key fingerprint** shown on the site with central's dashboard.
5. Make sure the site has its cron (cPanel → Cron Jobs, every minute):
   `* * * * * php /home/USER/catalog/artisan schedule:run >> /dev/null 2>&1`
   The Updates page warns and shows the exact command if check-ins aren't running.

The site appears as Active within one check-in, with its version, PHP and modules.

Lost connection or suspected leak: Installation → ⋯ → **Revoke access** (instant) or **New connection code** (old credentials stop working).

## 3. Create a release

### Straight from GitHub (recommended)

Once: Central → **Releases → From GitHub → Add repository**: `owner/name`, a token if the repository is private (fine-grained, read-only *Contents*), and what it contains (core app, a module, or a client extension + which client). Choose:
- **Import automatically**: central checks GitHub every hour and imports new releases.
- **Publish automatically**: imported releases are signed and ready to deploy at once (otherwise they wait as drafts for you to check).

Every time: bump `VERSION` (or `module.json`), commit, push a tag (`git tag v1.2.0 && git push origin v1.2.0`). The workflow builds the package and creates the GitHub release; central imports it (or click **Import** on the GitHub page). Then **Deploy**.

Checks on import: GitHub's SHA-256 of the file, the package type matches the repository's setting, and the tag's version matches the package's `VERSION`/`module.json`. A module repository needs a workflow that zips the module folder and attaches it to the release (any `*.zip`).

### Core (manual upload)

1. In the ecommerce repo: set `VERSION` (e.g. `1.2.0`), commit, `git tag v1.2.0 && git push origin v1.2.0`.
2. The GitHub workflow builds `catalog-v1.2.0.zip` (production PHP packages + compiled assets, no `.env`, `storage`, `modules`, `extensions`).
3. Central → **Releases → New release** → upload the zip, or on the central server:
   `php artisan central:import-release catalog-v1.2.0.zip --notes="…" --php=">=8.2" [--from-min=1.1.0] [--db-rollback]`
4. Check the draft (notes, requirements, "migrations can be rolled back" only if every new migration has a working `down()`), then **Sign & publish**.

Local build without GitHub: `php artisan release:package core` in the ecommerce app (includes your local `vendor/`, so run `composer install --no-dev` first).

### Module or client extension

Folder layout (see `docs/examples/`):

```
gift-wrap/
  module.json          {"slug","name","version","type":"module|extension","namespace","provider","requires"}
  src/…                PSR-4 under "namespace"
  routes/web.php       optional
  database/migrations/ optional
  resources/views/     optional (namespace = slug)
```

Package it from a site where it lives: `php artisan release:package module gift-wrap` or `php artisan release:package extension acme-pay`, or zip the folder yourself.
Upload it. **Extensions must be assigned to their client**, and can never be deployed to anyone else.

Releases can't be edited after publishing and each version number can be used once. To stop a bad release: **Withdraw** (cancels updates not yet picked up; installed sites are unchanged).

## 4. Deploy

Central → **Deployments → New deployment** (or **Deploy** on a release or installation):

1. Choose the release.
2. Choose websites: filter by client, status, core version or module, then select all shown, several, or one.
3. **Confirmation screen**: who gets it (with current → new version), who is skipped and why.
4. Options: **schedule** for later; **test on one website first** (others only follow if it succeeds); automatic **retries** (temporary errors only); **overwrite modified core files** (off by default).
5. Watch it on the deployment page (refreshes live). A site picks it up at its next check-in.

Statuses: *Queued* (waiting for check-in) → *Picked up* → *Installing* → **Succeeded** / **Failed** (reason + log). *Will retry*, *Waiting for test site*, *No response* (silent 3 h after hand-over: check the site, it was **not** assumed successful).

- **Cancel pending**: stops sites that haven't picked it up. Running installs finish (interrupting them is unsafe).
- **Retry failed**: re-queues failed/no-response sites that are still compatible.
- **Roll back** (per site, on a succeeded update): the site undoes that release's migrations (if marked reversible) and restores its previous files from its own backup.

## 5. What a site owner sees

**Admin → Updates** shows the connection, last check-in, waiting updates (with **Install now**), modules/extensions, the local update log, server readiness checks and **Previous versions** (restore files; download the database backup taken before each update).

## 6. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Site stays *Queued* | Cron not running on the site (Updates page shows the command), or site suspended/offline |
| `modified_files` | Core files edited on that site. Move the change into an extension (CUSTOMIZATIONS.md), or redeploy with overwrite on |
| `incompatible` | The site's state changed since the deployment was created (e.g. module version). Read the reason; deploy the prerequisite first |
| `clock_skew` | The site server's clock is off by more than 5 minutes; ask the host to fix NTP |
| `network` / `checksum` | Temporary; retried automatically. Persistent: check central's HTTPS certificate and that the site can make outgoing HTTPS requests |
| `server_not_ready` | PHP zip/curl missing, folders not writable or low disk space; the site's Updates page lists the fix |
| Site stuck in maintenance | Admin → Updates → **Bring website online** (the update routes keep working during maintenance) |
| `finish_failed` | Usually a migration error. Files were restored automatically; the DB backup is downloadable on the site |

## 7. Local testing setup (what was used to test this)

Central on `:8100`, two independent shop copies on `:8001`/`:8002`, each with its own database and `APP_KEY`.
Plain HTTP was allowed only for that: `CENTRAL_ALLOW_INSECURE_API=true` (central) and `AGENT_ALLOW_INSECURE=true` (sites). **Never set these in production.**
